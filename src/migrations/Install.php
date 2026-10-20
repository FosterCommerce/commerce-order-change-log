<?php

declare(strict_types=1);

namespace fostercommerce\orderchangelog\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use fostercommerce\orderchangelog\db\Table;

class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable(Table::CHANGES, [
			'id' => $this->primaryKey(),
			'orderId' => $this->integer()->notNull(),
			'userId' => $this->integer(),
			'userName' => $this->string(),
			'impersonatorId' => $this->integer(),
			'impersonatorName' => $this->string(),
			'data' => $this->json()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::CHANGES, ['orderId']);
		$this->createIndex(null, Table::CHANGES, ['userId']);
		$this->createIndex(null, Table::CHANGES, ['impersonatorId']);
		$this->addForeignKey(null, Table::CHANGES, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::CHANGES, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::CHANGES, ['impersonatorId'], CraftTable::USERS, ['id'], 'SET NULL');

		$this->createTable(Table::JOB_USERS, [
			'id' => $this->primaryKey(),
			'jobId' => $this->string()->notNull(),
			'userId' => $this->integer()->notNull(),
			'impersonatorId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::JOB_USERS, ['jobId'], true);
		$this->createIndex(null, Table::JOB_USERS, ['userId']);
		$this->createIndex(null, Table::JOB_USERS, ['impersonatorId']);
		$this->addForeignKey(null, Table::JOB_USERS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::JOB_USERS, ['impersonatorId'], CraftTable::USERS, ['id'], 'SET NULL');

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::JOB_USERS);
		$this->dropTableIfExists(Table::CHANGES);

		return true;
	}
}
