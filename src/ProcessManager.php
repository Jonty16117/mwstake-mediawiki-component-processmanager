<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use DateInterval;
use DateTime;
use Symfony\Component\Process\Process;
use Wikimedia\Rdbms\ILoadBalancer;

class ProcessManager {
	/** @var ILoadBalancer */
	private $loadBalancer;
	/** @var int Number of minutes after which to delete processes */
	private $garbageInterval = 600;

	/**
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	public function getProcessInfo( $pid ): ?ProcessInfo {
		return $this->loadProcess( $pid );
	}

	/**
	 * @param string $pid
	 * @return string|null
	 */
	public function getProcessStatus( $pid ): ?string {
		$processInfo = $this->loadProcess( $pid );
		if ( $processInfo ) {
			return $processInfo->getState();
		}

		return null;
	}

	/**
	 * @param ManagedProcess $process
	 * @param array|null $data
	 * @return string
	 */
	public function startProcess( ManagedProcess $process, $data = [] ): string {
		return $this->enqueueProcess( $process->getSteps(), $process->getTimeout(), $data );
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	private function loadProcess( $pid ): ?ProcessInfo {
		$this->garbageCollect();

		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $db->selectRow(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started',
				'p_timeout',
				'p_output',
				'p_steps'
			],
			[
				'p_pid' => $pid
			],
			__METHOD__
		);

		if ( !$row ) {
			return null;
		}
		return ProcessInfo::newFromRow( $row );
	}

	/**
	 * Record end of the process
	 *
	 * @param string $pid
	 * @param int $exitCode
	 * @param string $exitStatus
	 * @param array|null $data
	 * @return bool
	 */
	public function recordFinish( $pid, int $exitCode, string $exitStatus = '', $data = [] ) {
		return $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_TERMINATED,
			'p_exitcode' => $exitCode,
			'p_exitstatus' => $exitStatus,
			'p_output' => json_encode( $data )
		] );
	}

	/**
	 * @param string $pid
	 * @param string $lastStep
	 * @param array $data
	 * @return bool
	 */
	public function recordInterrupt( $pid, $lastStep, $data ) {
		return $this->updateInfo( $pid, [
			'p_state' => InterruptingProcessStep::STATUS_INTERRUPTED,
			'p_output' => json_encode( [
				'lastStep' => $lastStep,
				'data' => $data,
			] )
		] );
	}

	/**
	 * @param array $steps
	 * @param int $timeout
	 * @param array|null $data
	 *
	 * @return string|null if failed to enqueue
	 */
	private function enqueueProcess( array $steps, int $timeout, ?array $data = [] ): ?string {
		$pid = md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );

		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$res = $db->insert(
			'processes',
			[
				'p_pid' => $pid,
				'p_state' => Process::STATUS_READY,
				'p_timeout' => $timeout,
				'p_started' => $db->timestamp( ( new DateTime() )->format( 'YmdHis' ) ),
				'p_output' => json_encode( $data ),
				'p_steps' => json_encode( $steps )
			],
			__METHOD__
		);

		return $res ? $pid : null;
	}

	/**
	 * @param string $pid
	 * @return string
	 * @throws \Exception
	 */
	public function proceed( $pid ): ?string {
		$info = $this->getProcessInfo( $pid );
		if ( !$info ) {
			throw new \Exception( 'Process with PID ' . $pid . ' does not exist' );
		}
		if ( $info->getState() !== InterruptingProcessStep::STATUS_INTERRUPTED ) {
			throw new \Exception( 'Process was not previously interrupted' );
		}
		$steps = $info->getSteps();
		$lastData = $info->getOutput();
		$lastStep = $lastData['lastStep'] ?? null;
		$lastData = $lastData['data'] ?? [];
		if ( !$lastStep ) {
			throw new \Exception( 'No last step information available' );
		}
		$remainingSteps = [];
		$found = false;
		foreach ( $steps as $name => $spec ) {
			if ( $name === $lastStep ) {
				$found = true;
				continue;
			}
			if ( $found ) {
				$remainingSteps[$name] = $spec;
			}
		}

		if ( empty( $remainingSteps ) ) {
			$this->recordFinish( $pid, 0, 'No steps left after proceeding', $lastData );
			return $pid;
		}

		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$res = $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_READY,
			'p_output' => json_encode( $lastData ),
			'p_steps' => json_encode( $remainingSteps )
		] );

		return $res ? $pid : null;
	}

	/**
	 * Record starting of the process
	 *
	 * @param string $pid
	 * @return bool
	 */
	public function recordStart( $pid ): bool {
		return $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_STARTED,
		] );
	}

	/**
	 * @return array
	 */
	public function getEnqueuedProcesses(): array {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $db->select(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started',
				'p_timeout',
				'p_output',
				'p_steps'
			],
			[
				'p_state' => Process::STATUS_READY
			],
			__METHOD__
		);
		$processes = [];
		foreach ( $res as $row ) {
			$processes[] = ProcessInfo::newFromRow( $row );
		}
		return $processes;
	}

	/**
	 * @param string $pid
	 * @param array $data
	 * @return bool
	 */
	private function updateInfo( $pid, array $data ) {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $db->update(
			'processes',
			$data,
			[ 'p_pid' => $pid ],
			__METHOD__
		);
	}

	/**
	 * Delete all processes older than 1h
	 */
	private function garbageCollect() {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$hourAgo = ( new DateTime() )->sub( new DateInterval( "PT{$this->garbageInterval}M" ) );
		$db->delete(
			'processes',
			[
				'p_started < ' . $db->timestamp( $hourAgo->format( 'YmdHis' ) ),
				// Status is not PROCESS_INTERRUPTED
				'p_state != ' . $db->addQuotes( InterruptingProcessStep::STATUS_INTERRUPTED ),
			],
			__METHOD__
		);
	}

	/**
	 * Check is ProcessRunner is running
	 * @return bool
	 */
	public function isRunnerRunning(): bool {
		$file = sys_get_temp_dir() . '/process-runner.pid';
		if ( file_exists( $file ) ) {
			$pid = file_get_contents( $file );
			if ( $pid ) {
				$pid = (int)$pid;
				if ( posix_getsid( $pid ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Store PID of the ProcessRunner instance
	 *
	 * @param int $pid
	 *
	 * @return bool
	 */
	public function storeProcessRunnerId( $pid ): bool {
		$file = sys_get_temp_dir() . '/process-runner.pid';
		return (bool)file_put_contents( $file, $pid );
	}

	/**
	 * Clear PID of the ProcessRunner instance
	 * @return bool
	 */
	public function clearProcesseRunnerId(): bool {
		$file = sys_get_temp_dir() . '/process-runner.pid';
		return (bool)file_put_contents( $file, '' );
	}
}
