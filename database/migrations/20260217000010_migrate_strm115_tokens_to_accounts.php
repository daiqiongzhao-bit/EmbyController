<?php
use think\migration\Migrator;

class MigrateStrm115TokensToAccounts extends Migrator
{
    public function up()
    {
        if (!$this->hasTable('config') || !$this->hasTable('strm115_account')) {
            return;
        }

        $prefix = (string)($this->getAdapter()->getOption('table_prefix') ?? '');
        $cfgTable = $prefix . 'config';
        $accTable = $prefix . 'strm115_account';

        // If any account already exists, don't auto-migrate again.
        $cntRow = $this->fetchRow('SELECT COUNT(1) AS c FROM ' . $accTable);
        $cnt = (int)($cntRow['c'] ?? 0);
        if ($cnt > 0) {
            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $get = function($key) use ($cfgTable, $pdo) {
            $row = $this->fetchRow("SELECT value FROM {$cfgTable} WHERE appName='strm115' AND `key`=" . $pdo->quote($key) . ' LIMIT 1');
            return $row ? (string)$row['value'] : '';
        };

        $access = $get('b2_access_token');
        $refresh = $get('b2_refresh_token');
        $name = $get('b2_name');
        $clientId = $get('b2_client_id');
        $expiresIn = (int)($get('b2_expires_in') ?: 0);
        $expiresAt = (int)($get('b2_expires_at') ?: 0);

        if ($name === '') $name = '115（迁移）';

        // Insert even if token empty (to create a visible placeholder), but keep default only when enabled or has access.
        $isDefault = 1;
        $this->execute(
            'INSERT INTO ' . $accTable . ' (createdAt, updatedAt, name, client_id, access_token, refresh_token, expires_in, expires_at, is_default, status) VALUES ' .
            '(CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ' .
            $pdo->quote($name) . ',' .
            ($clientId !== '' ? $pdo->quote($clientId) : 'NULL') . ',' .
            ($access !== '' ? $pdo->quote($access) : 'NULL') . ',' .
            ($refresh !== '' ? $pdo->quote($refresh) : 'NULL') . ',' .
            (int)$expiresIn . ',' . (int)$expiresAt . ',' . (int)$isDefault . ',1)'
        );

        // Record default account id into config for faster lookups (optional)
        $idRow = $this->fetchRow('SELECT id FROM ' . $accTable . ' WHERE is_default=1 ORDER BY id ASC LIMIT 1');
        if ($idRow && isset($idRow['id'])) {
            $defaultId = (string)$idRow['id'];
            $row = $this->fetchRow("SELECT id FROM {$cfgTable} WHERE appName='strm115' AND `key`='default_account_id' LIMIT 1");
            if ($row && isset($row['id'])) {
                $this->execute('UPDATE ' . $cfgTable . ' SET value=' . $pdo->quote($defaultId) . " WHERE id=" . (int)$row['id']);
            } else {
                $this->execute('INSERT INTO ' . $cfgTable . " (createdAt, updatedAt, appName, `key`, value, type, status) VALUES (CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'strm115','default_account_id'," . $pdo->quote($defaultId) . ",0,1)");
            }
        }
    }

    public function down()
    {
        // no-op
    }
}
