<?php
use think\migration\Migrator;

class AddRemarkToStrm115Account extends Migrator
{
    public function change()
    {
        if (!$this->hasTable('strm115_account')) {
            return;
        }

        $t = $this->table('strm115_account');
        if (!$t->hasColumn('remark')) {
            $t->addColumn('remark', 'string', ['limit' => 128, 'default' => '', 'comment' => '备注'])->update();
        }
    }
}
