<?php declare(strict_types = 1);

namespace OriNette\Predis;

use Predis\Client;
use SessionHandlerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\RedisStore;
use function ini_get;

final class SessionHandler implements SessionHandlerInterface
{

	private Client $client;

	private LockFactory $lockFactory;

	private int $sessionTtl;

	private int $lockTtl;

	/** @var array<string, LockInterface> */
	private array $locks = [];

	public function __construct(Client $client, ?int $sessionTtl = null, ?int $lockTtl = null)
	{
		$this->sessionTtl = $this->getTtl($sessionTtl, 'session.gc_maxlifetime', 86_400);
		$this->lockTtl = $this->getTtl($lockTtl, 'max_execution_time', 30);

		$this->client = $client;
		$this->lockFactory = new LockFactory(new RedisStore($client, $this->lockTtl));
	}

	private function getTtl(?int $givenTtl, string $iniOptionName, int $default): int
	{
		if ($givenTtl !== null) {
			return $givenTtl;
		}

		$iniOption = ini_get($iniOptionName);

		return $iniOption !== ''
			? (int) $iniOption
			: $default;
	}

	/**
	 * @param string $save_path
	 * @param string $name
	 *
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 */
	public function open($save_path, $name): bool
	{
		if (!$this->client->isConnected()) {
			$this->client->connect();
		}

		return true;
	}

	public function close(): bool
	{
		foreach ($this->locks as $lock) {
			$lock->release();
		}

		$this->locks = [];

		return true;
	}

	/**
	 * @param string $session_id
	 *
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 */
	public function destroy($session_id): bool
	{
		$this->client->del(
			$this->prefix($session_id),
		);

		$this->releaseLock($session_id);

		return true;
	}

	/**
	 * @param int $maxlifetime
	 *
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 */
	public function gc($maxlifetime): int
	{
		return 0;
	}

	/**
	 * @param string $session_id
	 *
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 */
	public function read($session_id): string
	{
		$this->acquireLock($session_id);

		$data = $this->client->get(
			$this->prefix($session_id),
		);

		return $data ?? '';
	}

	/**
	 * @param string $session_id
	 * @param string $session_data
	 *
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 */
	public function write($session_id, $session_data): bool
	{
		$this->client->setex(
			$this->prefix($session_id),
			$this->sessionTtl,
			$session_data,
		);

		return true;
	}

	private function prefix(string $session_id): string
	{
		return "orisai.session.$session_id";
	}

	private function acquireLock(string $session_id): void
	{
		$lock = $this->locks[$session_id]
			?? ($this->locks[$session_id] = $this->lockFactory->createLock($session_id, $this->lockTtl));

		if (!$lock->isAcquired()) {
			$lock->acquire(true);
		}
	}

	private function releaseLock(string $session_id): void
	{
		if (!isset($this->locks[$session_id])) {
			return;
		}

		$lock = $this->locks[$session_id];

		$lock->release();

		unset($this->locks[$session_id]);
	}

}
