<?php
/**
 * Client groups: all configs of one account, switched on/off together.
 */
class ClientGroup {
    public static function create(int $userId, string $name, ?string $comment = null): int {
        $name = trim($name);
        if ($name === '') {
            throw new Exception('Group name is required');
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('INSERT INTO client_groups (user_id, name, comment) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $name, $comment !== null && trim($comment) !== '' ? trim($comment) : null]);
        return (int)$pdo->lastInsertId();
    }

    public static function get(int $groupId): ?array {
        $stmt = DB::conn()->prepare('SELECT * FROM client_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function canAccess(array $group, array $user): bool {
        return $group['user_id'] == $user['id'] || ($user['role'] ?? '') === 'admin';
    }

    /**
     * Groups visible to the user (admin sees all), with config counters.
     */
    public static function listForUser(array $user): array {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $sql = '
            SELECT g.*, (SELECT name FROM users WHERE id = g.user_id) AS owner_name,
                   COUNT(c.id) AS total_count,
                   COALESCE(SUM(c.status = \'active\'), 0) AS active_count
            FROM client_groups g
            LEFT JOIN vpn_clients c ON c.group_id = g.id
            ' . ($isAdmin ? '' : 'WHERE g.user_id = ?') . '
            GROUP BY g.id
            ORDER BY g.created_at DESC
        ';
        $stmt = DB::conn()->prepare($sql);
        $stmt->execute($isAdmin ? [] : [$user['id']]);
        return $stmt->fetchAll();
    }

    /**
     * Plain id/name/status list for group pickers.
     */
    public static function options(array $user): array {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $stmt = DB::conn()->prepare(
            'SELECT id, name, status FROM client_groups ' . ($isAdmin ? '' : 'WHERE user_id = ? ') . 'ORDER BY name'
        );
        $stmt->execute($isAdmin ? [] : [$user['id']]);
        return $stmt->fetchAll();
    }

    public static function members(int $groupId): array {
        $stmt = DB::conn()->prepare('
            SELECT c.*, s.name AS server_name, s.host AS server_host
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON s.id = c.server_id
            WHERE c.group_id = ?
            ORDER BY c.created_at DESC
        ');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    /**
     * Clients of the user not in any group (for "add existing config").
     */
    public static function ungroupedClients(array $user): array {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $stmt = DB::conn()->prepare('
            SELECT c.id, c.name, s.name AS server_name
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON s.id = c.server_id
            WHERE c.group_id IS NULL ' . ($isAdmin ? '' : 'AND c.user_id = ? ') . '
            ORDER BY c.name
        ');
        $stmt->execute($isAdmin ? [] : [$user['id']]);
        return $stmt->fetchAll();
    }

    public static function update(int $groupId, string $name, ?string $comment): bool {
        $name = trim($name);
        if ($name === '') {
            throw new Exception('Group name is required');
        }
        $stmt = DB::conn()->prepare('UPDATE client_groups SET name = ?, comment = ? WHERE id = ?');
        return $stmt->execute([$name, $comment !== null && trim($comment) !== '' ? trim($comment) : null, $groupId]);
    }

    /**
     * Delete the group; configs stay (disabled ones remain disabled and can be restored one by one).
     */
    public static function delete(int $groupId): bool {
        $pdo = DB::conn();
        $pdo->prepare('UPDATE vpn_clients SET disabled_by_group = 0 WHERE group_id = ?')->execute([$groupId]);
        return $pdo->prepare('DELETE FROM client_groups WHERE id = ?')->execute([$groupId]);
    }

    /**
     * Disable the group and cut off every active config in it.
     * Safe to repeat: only configs that are still active are processed.
     *
     * @return array{disabled: int, failed: string[]}
     */
    public static function disable(int $groupId): array {
        $pdo = DB::conn();
        // Flag the group first so nobody can restore a single config while we work
        $pdo->prepare('UPDATE client_groups SET status = \'disabled\', disabled_at = COALESCE(disabled_at, NOW()) WHERE id = ?')
            ->execute([$groupId]);

        $stmt = $pdo->prepare('SELECT id, name FROM vpn_clients WHERE group_id = ? AND status = \'active\'');
        $stmt->execute([$groupId]);

        $disabled = 0;
        $failed = [];
        foreach ($stmt->fetchAll() as $row) {
            try {
                (new VpnClient((int)$row['id']))->revoke(true, true);
                $disabled++;
            } catch (Exception $e) {
                error_log("Group {$groupId}: failed to disable client {$row['id']}: " . $e->getMessage());
                $failed[] = $row['name'] . ': ' . $e->getMessage();
            }
        }

        return ['disabled' => $disabled, 'failed' => $failed];
    }

    /**
     * Enable the group and restore configs that the group switched off.
     * Configs disabled on their own (manually, expired) stay disabled.
     *
     * @return array{restored: int, skipped: int, failed: string[]}
     */
    public static function enable(int $groupId): array {
        $pdo = DB::conn();
        $pdo->prepare('UPDATE client_groups SET status = \'active\', disabled_at = NULL WHERE id = ?')
            ->execute([$groupId]);

        $stmt = $pdo->prepare('SELECT id, name, expires_at FROM vpn_clients WHERE group_id = ? AND status = \'disabled\' AND disabled_by_group = 1');
        $stmt->execute([$groupId]);

        $restored = 0;
        $skipped = 0;
        $failed = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
                // Expired meanwhile: leave it disabled, no longer tied to the group switch
                $pdo->prepare('UPDATE vpn_clients SET disabled_by_group = 0 WHERE id = ?')->execute([$row['id']]);
                $skipped++;
                continue;
            }
            try {
                (new VpnClient((int)$row['id']))->restore();
                $restored++;
            } catch (Exception $e) {
                error_log("Group {$groupId}: failed to restore client {$row['id']}: " . $e->getMessage());
                $failed[] = $row['name'] . ': ' . $e->getMessage();
            }
        }

        return ['restored' => $restored, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Put a client into a group (or take it out with $groupId = null).
     * Access to both the client and the group is checked against $user.
     */
    public static function assignClient(int $clientId, ?int $groupId, array $user): void {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $pdo = DB::conn();

        $stmt = $pdo->prepare('SELECT id, user_id, status FROM vpn_clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) {
            throw new Exception('Client not found');
        }
        if ($client['user_id'] != $user['id'] && !$isAdmin) {
            throw new Exception('Forbidden');
        }

        if ($groupId === null) {
            $pdo->prepare('UPDATE vpn_clients SET group_id = NULL, disabled_by_group = 0 WHERE id = ?')->execute([$clientId]);
            return;
        }

        $group = self::get($groupId);
        if (!$group || !self::canAccess($group, $user)) {
            throw new Exception('Group not found');
        }
        if ($group['status'] === 'disabled' && $client['status'] === 'active') {
            throw new Exception('Group is disabled: enable it before adding active configs');
        }

        $pdo->prepare('UPDATE vpn_clients SET group_id = ?, disabled_by_group = 0 WHERE id = ?')->execute([$groupId, $clientId]);
    }
}
