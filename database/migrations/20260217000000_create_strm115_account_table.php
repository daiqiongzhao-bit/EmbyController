<?php
use think\migration\Migrator;

class CreateStrm115AccountTable extends Migrator
{
    public function change()
    {
        if ($this->hasTable('strm115_account')) {
            return;
        }

        $this->table('strm115_account')
            ->addColumn('createdAt', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addColumn('updatedAt', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP', 'comment' => '更新时间'])
            ->addColumn('name', 'string', ['limit' => 64, 'default' => '115', 'comment' => '账号名称'])
            ->addColumn('client_id', 'string', ['limit' => 32, 'null' => true, 'comment' => 'B2 ClientID/AppID'])
            ->addColumn('access_token', 'text', ['null' => true])
            ->addColumn('refresh_token', 'text', ['null' => true])
            ->addColumn('expires_in', 'integer', ['default' => 0])
            ->addColumn('expires_at', 'integer', ['default' => 0])
            ->addColumn('is_default', 'integer', ['default' => 0, 'comment' => '1默认账号'])
            ->addColumn('status', 'integer', ['default' => 1, 'comment' => '1启用0停用'])
            ->addIndex(['is_default'])
            ->create();
    }
}
