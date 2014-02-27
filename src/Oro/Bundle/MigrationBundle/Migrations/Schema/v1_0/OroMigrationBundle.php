<?php

namespace Oro\Bundle\MigrationBundle\Migrations\Schema\v1_0;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;

class OroMigrationBundle implements Migration
{
    /**
     * @inheritdoc
     */
    public function up(Schema $schema)
    {
        $table = $schema->createTable('oro_migrations_data');
        $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
        $table->addColumn('class_name', 'string', ['default' => null, 'notnull' => true, 'length' => 255]);
        $table->addColumn('loaded_at', 'datetime', ['notnull' => true]);
        $table->setPrimaryKey(['id']);

        return [];
    }
}
