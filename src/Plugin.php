<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\elements\Address;
use craft\events\ModelEvent;
use craft\events\TemplateEvent;
use craft\queue\Queue;
use craft\services\Gc;
use craft\web\View;
use fostercommerce\orderchangelog\models\Settings;
use fostercommerce\orderchangelog\services\Changes;
use fostercommerce\orderchangelog\services\JobUsers;
use yii\base\Event;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Changes $changes
 * @property-read JobUsers $jobUsers
 */
class Plugin extends BasePlugin
{
	public const HANDLE = 'order-change-log';

	public string $schemaVersion = '1.0.0';

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public static function config(): array
	{
		return [
			'components' => [
				'changes' => Changes::class,
				'jobUsers' => JobUsers::class,
			],
		];
	}

	#[\Override]
	public function init(): void
	{
		parent::init();

		$this->registerOrderSaveHandlers();
		$this->registerQueueHandlers();

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerOrderTab();
		}
	}

	public function getChanges(): Changes
	{
		/** @var Changes $changes */
		$changes = $this->get('changes');
		return $changes;
	}

	public function getJobUsers(): JobUsers
	{
		/** @var JobUsers $jobUsers */
		$jobUsers = $this->get('jobUsers');
		return $jobUsers;
	}

	#[\Override]
	protected function createSettingsModel(): Settings
	{
		return new Settings();
	}

	private function registerOrderSaveHandlers(): void
	{
		Event::on(Order::class, Element::EVENT_BEFORE_SAVE, function (ModelEvent $modelEvent): void {
			/** @var Order $order */
			$order = $modelEvent->sender;
			// The completing save sets isCompleted before this check, so it reads the cart state the placed order is recorded from
			if ($order->isCompleted && $order->id !== null) {
				$this->getChanges()->rememberStateBeforeSave($order->id);
			}
		});

		Event::on(Order::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $modelEvent): void {
			/** @var Order $order */
			$order = $modelEvent->sender;
			/** @var int $orderId */
			$orderId = $order->id;
			$this->getChanges()->recordChangesAfterSave($orderId);
		});

		// Watch address saves too, since an order address can be saved without saving the order
		Event::on(Address::class, Element::EVENT_BEFORE_SAVE, function (ModelEvent $modelEvent): void {
			/** @var Address $address */
			$address = $modelEvent->sender;
			$ownerId = $address->getOwnerId();
			if ($ownerId !== null) {
				$this->getChanges()->rememberStateBeforeSave($ownerId);
			}
		});

		Event::on(Address::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $modelEvent): void {
			/** @var Address $address */
			$address = $modelEvent->sender;
			$ownerId = $address->getOwnerId();
			if ($ownerId !== null) {
				$this->getChanges()->recordChangesAfterSave($ownerId);
			}
		});
	}

	private function registerQueueHandlers(): void
	{
		Event::on(Queue::class, Queue::EVENT_AFTER_PUSH, function (PushEvent $pushEvent): void {
			$this->getJobUsers()->rememberPusher($pushEvent);
		});

		Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, function (ExecEvent $execEvent): void {
			$this->getJobUsers()->startJob($execEvent);
		});

		Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, function (ExecEvent $execEvent): void {
			$this->getJobUsers()->finishJob($execEvent);
		});

		Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, function (): void {
			$this->getJobUsers()->stopJob();
		});

		Event::on(Gc::class, Gc::EVENT_RUN, function (): void {
			$this->getJobUsers()->deleteStaleRows();
			$this->getChanges()->purgeExpiredChanges();
		});
	}

	private function registerOrderTab(): void
	{
		Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function (TemplateEvent $templateEvent): void {
			// Add the tab only on the order edit page, since other CP pages pass tabs too
			if ($templateEvent->template !== 'commerce/orders/_edit') {
				return;
			}

			/** @var array<string, mixed> $tabs */
			$tabs = $templateEvent->variables['tabs'];
			$tabs[self::HANDLE] = [
				'label' => Craft::t(self::HANDLE, 'tab.label'),
				'url' => '#orderChangeLogTab',
				'class' => null,
			];
			$templateEvent->variables['tabs'] = $tabs;
		});

		Craft::$app->getView()->hook('cp.commerce.order.content', function (array &$context): string {
			/** @var Order $order */
			$order = $context['order'];
			/** @var int $orderId */
			$orderId = $order->id;

			return Craft::$app->getView()->renderTemplate(self::HANDLE . '/_order-tab', [
				'order' => $order,
				'changes' => $this->getChanges()->getChangesForOrder($orderId),
			]);
		});
	}
}
