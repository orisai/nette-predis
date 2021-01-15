<?php declare(strict_types = 1);

namespace OriNette\Predis\DI;

use Nette\Bridges\HttpDI\SessionExtension;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Http\Session;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use OriNette\Predis\SessionHandler;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Logic\InvalidState;
use Predis\Client;
use stdClass;
use function assert;

/**
 * @property-read stdClass $config
 */
final class PredisExtension extends CompilerExtension
{

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'connections' => Expect::arrayOf(
				Expect::structure([
					'parameters' => Expect::array()->default([]),
					'options' => Expect::array()->default([]),
					'autowired' => Expect::bool()->default(false),
				]),
			),
			'session' => Expect::anyOf(
				Expect::structure([
					'connection' => Expect::string()->required(),
					'sessionTtl' => Expect::anyOf(Expect::int(), Expect::null()),
					'lockTtl' => Expect::anyOf(Expect::int(), Expect::null()),
				]),
				Expect::null(),
			),
		]);
	}

	public function loadConfiguration(): void
	{
		parent::loadConfiguration();

		$builder = $this->getContainerBuilder();
		$config = $this->config;

		foreach ($config->connections as $connectionKey => $connectionConfig) {
			$builder->addDefinition($this->formatConnectionName($connectionKey))
				->setFactory(Client::class, [
					$connectionConfig->parameters,
					$connectionConfig->options,
				])
				->setAutowired($connectionConfig->autowired);
		}

		$this->loadConfigurationSession($config->session);
	}

	public function beforeCompile(): void
	{
		parent::beforeCompile();

		$config = $this->config;

		$this->beforeCompileSession($config->session);
	}

	private function loadConfigurationSession(?stdClass $sessionConfig): void
	{
		if ($sessionConfig === null) {
			return;
		}

		$builder = $this->getContainerBuilder();

		$sessionConnectionKey = $this->prefix('session.connection');
		$sessionConnectionName = $this->formatConnectionName($sessionConfig->connection);

		if (!$builder->hasDefinition($sessionConnectionName)) {
			throw InvalidArgument::create()
				->withMessage(
					"{$sessionConnectionKey} with value {$sessionConfig->connection} requires {$sessionConnectionName} to be configured.",
				);
		}

		$builder->addDefinition($this->prefix('sessionHandler'))
			->setFactory(SessionHandler::class, [
				$builder->getDefinition($sessionConnectionName),
				$sessionConfig->sessionTtl,
				$sessionConfig->lockTtl,
			])
			->setAutowired(false);
	}

	private function beforeCompileSession(?stdClass $sessionConfig): void
	{
		if ($sessionConfig === null) {
			return;
		}

		$builder = $this->getContainerBuilder();

		$sessionConfigKey = $this->prefix('session');

		if ($builder->getByType(Session::class) === null) {
			$sessionClass = Session::class;
			$sessionExtensionClass = SessionExtension::class;

			throw InvalidState::create()
				->withMessage(
					"{$sessionClass} must be available in DI to configure {$sessionConfigKey}. Install nette/http and register {$sessionExtensionClass}.",
				);
		}

		$sessionDefinition = $builder->getDefinitionByType(Session::class);
		assert($sessionDefinition instanceof ServiceDefinition);

		$sessionDefinition->addSetup('setHandler', [
			$builder->getDefinition($this->prefix('sessionHandler')),
		]);
	}

	private function formatConnectionName(string $key): string
	{
		return $this->prefix("connection.{$key}");
	}

}
