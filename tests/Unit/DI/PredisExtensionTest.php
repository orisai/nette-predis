<?php declare(strict_types = 1);

namespace Tests\OriNette\Predis\Unit\DI;

use Nette\Http\Session;
use OriNette\DI\Boot\ManualConfigurator;
use OriNette\Predis\SessionHandler;
use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Logic\InvalidState;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use function dirname;

final class PredisExtensionTest extends TestCase
{

	public function testMinimal(): void
	{
		$configurator = new ManualConfigurator(dirname(__DIR__, 3));
		$configurator->setDebugMode(true);
		$configurator->addConfig(__DIR__ . '/config.minimal.neon');

		$container = $configurator->createContainer();

		$client1 = $container->getService('predis.connection.first');
		self::assertInstanceOf(Client::class, $client1);
		self::assertSame($client1, $container->getByType(Client::class));
	}

	public function testFull(): void
	{
		$configurator = new ManualConfigurator(dirname(__DIR__, 3));
		$configurator->setDebugMode(true);
		$configurator->addConfig(__DIR__ . '/config.full.neon');

		$container = $configurator->createContainer();

		$client1 = $container->getService('predis.connection.first');
		self::assertInstanceOf(Client::class, $client1);
		self::assertSame($client1, $container->getByType(Client::class));

		$client2 = $container->getService('predis.connection.second');
		self::assertInstanceOf(Client::class, $client2);

		$client3 = $container->getService('predis.connection.third');
		self::assertInstanceOf(Client::class, $client3);

		self::assertFalse($container->isCreated('predis.sessionHandler'));
		$container->getByType(Session::class);
		self::assertTrue($container->isCreated('predis.sessionHandler'));

		$sessionHandler = $container->getService('predis.sessionHandler');
		self::assertInstanceOf(SessionHandler::class, $sessionHandler);
	}

	public function testInvalidSession(): void
	{
		$configurator = new ManualConfigurator(dirname(__DIR__, 3));
		$configurator->setDebugMode(true);
		$configurator->addConfig(__DIR__ . '/config.invalidSession.neon');

		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(
			'predis.session.connection with value unknown requires predis.connection.unknown to be configured.',
		);

		$configurator->createContainer();
	}

	public function testMissingSession(): void
	{
		$configurator = new ManualConfigurator(dirname(__DIR__, 3));
		$configurator->setDebugMode(true);
		$configurator->addConfig(__DIR__ . '/config.missingSession.neon');

		$this->expectException(InvalidState::class);
		$this->expectExceptionMessage(
			'Nette\Http\Session must be available in DI to configure predis.session. Install nette/http and register Nette\Bridges\HttpDI\SessionExtension.',
		);

		$configurator->createContainer();
	}

}
