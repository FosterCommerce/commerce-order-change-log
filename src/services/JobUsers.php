<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\web\User as WebUser;
use DateTime;
use fostercommerce\orderchangelog\db\Table;
use yii\base\Component;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;

/**
 * Tracks the user who pushed each queue job, so a job's order changes are recorded against that user.
 *
 * @phpstan-type ActingUserIds array{userId: ?int, impersonatorId: ?int}
 */
class JobUsers extends Component
{
	private ?string $runningJobId = null;

	/**
	 * Get the user to record for a change, and their impersonator.
	 *
	 * @return array{user: ?User, impersonator: ?User}
	 */
	public function getActingUsers(): array
	{
		$actingUserIds = $this->getActingUserIds();

		return [
			'user' => $this->findUser($actingUserIds['userId']),
			'impersonator' => $this->findUser($actingUserIds['impersonatorId']),
		];
	}

	/**
	 * Remember who pushed a job.
	 */
	public function rememberPusher(PushEvent $pushEvent): void
	{
		$actingUserIds = $this->getActingUserIds();
		if ($actingUserIds['userId'] === null) {
			return;
		}

		Db::insert(Table::JOB_USERS, [
			'jobId' => (string) $pushEvent->id,
			...$actingUserIds,
		]);
	}

	/**
	 * Use the job's user while the job runs, whoever's request runs the queue.
	 */
	public function startJob(ExecEvent $execEvent): void
	{
		$this->runningJobId = (string) $execEvent->id;
	}

	/**
	 * Forget a finished job's user.
	 */
	public function finishJob(ExecEvent $execEvent): void
	{
		$this->stopJob();
		Db::delete(Table::JOB_USERS, [
			'jobId' => (string) $execEvent->id,
		]);
	}

	/**
	 * Stop using a failed job's user, keeping the row so a retry of the job still records its user.
	 */
	public function stopJob(): void
	{
		$this->runningJobId = null;
	}

	/**
	 * Delete rows older than 30 days, left by failed or released jobs.
	 */
	public function deleteStaleRows(): void
	{
		Db::delete(Table::JOB_USERS, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime('-30 days'))]);
	}

	/**
	 * @return ActingUserIds
	 */
	private function getActingUserIds(): array
	{
		if ($this->runningJobId !== null) {
			/** @var array{userId: int|string, impersonatorId: int|string|null}|null $row */
			$row = (new Query())
				->select(['userId', 'impersonatorId'])
				->from(Table::JOB_USERS)
				->where([
					'jobId' => $this->runningJobId,
				])
				->one();

			return [
				'userId' => $row === null ? null : (int) $row['userId'],
				'impersonatorId' => $row === null || $row['impersonatorId'] === null ? null : (int) $row['impersonatorId'],
			];
		}

		$webOrConsoleUser = Craft::$app->getUser();
		$userId = $webOrConsoleUser->getIdentity()?->id;
		// Console requests have no session to impersonate from
		$impersonatorId = $webOrConsoleUser instanceof WebUser ? $webOrConsoleUser->getImpersonatorId() : null;

		return [
			'userId' => $userId,
			// Ignore a user impersonating themselves, as a `users/impersonate` console token login does
			'impersonatorId' => $impersonatorId === $userId ? null : $impersonatorId,
		];
	}

	private function findUser(?int $userId): ?User
	{
		return $userId === null ? null : Craft::$app->getUsers()->getUserById($userId);
	}
}
