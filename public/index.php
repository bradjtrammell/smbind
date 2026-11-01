<?php
declare(strict_types=1);

namespace Smbind;

require dirname(__DIR__) . '/src/bootstrap.php';

use InvalidArgumentException;
use RuntimeException;
use Throwable;

startSession();
header('Cache-Control: no-store, private');

function intId(mixed $value): int
{
    return Dns::number($value, 1, 2147483647, 'Identifier');
}

function requestZone(): array
{
    $result = zone(intId($_POST['zone_id'] ?? $_GET['id'] ?? $_GET['zone'] ?? '0'));
    if (!canEdit($result)) {
        throw new RuntimeException('You do not have permission to modify this DNS zone.', 403);
    }
    return $result;
}

function auditSafe(string $event, ?int $zoneId = null, array $detail = []): void
{
    audit($event, $zoneId, $detail);
}

function authenticate(): void
{
    assertCsrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $row = one('SELECT * FROM users WHERE username = ?', [$username]);
    $locked = $row !== null && $row['locked_until'] !== null && strtotime($row['locked_until'] . ' UTC') > time();
    $valid = !$locked && $row !== null && password_verify($password, $row['password_hash']);
    if (!$valid) {
        if ($row && !$locked) {
            query(
                'UPDATE users SET failed_logins = failed_logins + 1, locked_until = IF(failed_logins + 1 >= 5, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), NULL) WHERE id = ?',
                [$row['id']]
            );
        }
        throw new InvalidArgumentException('Invalid credentials or account temporarily locked. Please try again later.');
    }
    query('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$row['id']]);
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['user'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']];
    auditSafe('login');
    redirect('dashboard');
}

function handlePost(): never
{
    $action = (string) ($_POST['action'] ?? '');
    if ($action === '') {
        throw new InvalidArgumentException('Missing form action.');
    }
    assertCsrf();
    if ($action === 'login') {
        authenticate();
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('login');
    }
    $actor = requireUser();
    // Re-check database role on every POST: a revoked administrator must not retain session privileges.
    $authoritative = one('SELECT id,username,role FROM users WHERE id=?', [$actor['id']]);
    if (!$authoritative) {
        unset($_SESSION['user']);
        throw new RuntimeException('This account has been removed.', 403);
    }
    $actor = ['id' => (int) $authoritative['id'], 'username' => $authoritative['username'], 'role' => $authoritative['role']];
    $_SESSION['user'] = $actor;
    switch ($action) {
        case 'create_zone':
            requireAdmin();
            $name = Dns::domain((string) ($_POST['name'] ?? ''));
            $primary = Dns::domain((string) ($_POST['primary_ns'] ?? ''));
            $contact = Dns::domain((string) ($_POST['contact'] ?? ''));
            $ownerId = intId($_POST['owner_id'] ?? '0');
            if (!one('SELECT id FROM users WHERE id = ? AND role IN (?, ?)', [$ownerId, 'admin', 'editor'])) {
                throw new InvalidArgumentException('Choose an administrator or editor as the zone owner.');
            }
            $values = zoneValues($_POST);
            query(
                'INSERT INTO zones (name,owner_id,primary_ns,contact,default_ttl,refresh,retry,expire,minimum,serial) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$name, $ownerId, $primary, $contact, ...$values, 1]
            );
            $id = (int) db()->lastInsertId();
            auditSafe('zone.create', $id, ['name' => $name]);
            flash('success', 'Zone created. Add DNS records, then publish your changes.');
            redirect('zone', ['id' => $id]);
        case 'update_zone':
            $z = requestZone();
            $primary = Dns::domain((string) ($_POST['primary_ns'] ?? ''));
            $contact = Dns::domain((string) ($_POST['contact'] ?? ''));
            $values = zoneValues($_POST);
            $ownerId = (int) $z['owner_id'];
            if ($actor['role'] === 'admin' && isset($_POST['owner_id'])) {
                $ownerId = intId($_POST['owner_id']);
                if (!one('SELECT id FROM users WHERE id = ? AND role IN (?, ?)', [$ownerId, 'admin', 'editor'])) {
                    throw new InvalidArgumentException('Choose an administrator or editor as zone owner.');
                }
            }
            query(
                'UPDATE zones SET primary_ns=?,contact=?,default_ttl=?,refresh=?,retry=?,expire=?,minimum=?,owner_id=?,dirty=1,revision=revision+1 WHERE id=?',
                [$primary, $contact, ...$values, $ownerId, $z['id']]
            );
            auditSafe('zone.update', (int) $z['id']);
            flash('success', 'Zone settings saved. Changes are pending publication.');
            redirect('zone', ['id' => $z['id']]);
        case 'delete_zone':
            requireAdmin();
            $z = requestZone();
            auditSafe('zone.delete', (int) $z['id'], ['name' => $z['name']]);
            query('DELETE FROM zones WHERE id = ?', [$z['id']]);
            flash('success', 'Zone removed from SMBind. Publish to remove it from BIND configuration.');
            redirect('zones');
        case 'save_record':
            $z = requestZone();
            $data = Dns::validateRecord($_POST);
            $recordId = isset($_POST['record_id']) && $_POST['record_id'] !== '' ? intId($_POST['record_id']) : null;
            if ($recordId && !one('SELECT id FROM records WHERE id=? AND zone_id=?', [$recordId, $z['id']])) {
                throw new RuntimeException('Record was not found in this zone.', 404);
            }
            $conflict = one(
                'SELECT id FROM records WHERE zone_id=? AND host=? AND (type=? OR ?=?) AND (? IS NULL OR id<>?) LIMIT 1',
                [$z['id'], $data['host'], 'CNAME', $data['type'], 'CNAME', $recordId, $recordId ?? 0]
            );
            if ($conflict) {
                throw new InvalidArgumentException('CNAME records cannot coexist with another record at the same host.');
            }
            $recordValues = [$data['host'], $data['type'], $data['value'], $data['ttl'], $data['priority'], $data['weight'], $data['port']];
            db()->beginTransaction();
            try {
                if ($recordId) {
                    query(
                        'UPDATE records SET host=?,type=?,value=?,ttl=?,priority=?,weight=?,port=? WHERE id=? AND zone_id=?',
                        [...$recordValues, $recordId, $z['id']]
                    );
                } else {
                    query(
                        'INSERT INTO records (host,type,value,ttl,priority,weight,port,zone_id) VALUES (?,?,?,?,?,?,?,?)',
                        [...$recordValues, $z['id']]
                    );
                    $recordId = (int) db()->lastInsertId();
                }
                query('UPDATE zones SET dirty=1,revision=revision+1 WHERE id=?', [$z['id']]);
                db()->commit();
            } catch (Throwable $error) {
                db()->rollBack();
                throw $error;
            }
            auditSafe('record.save', (int) $z['id'], ['record_id' => $recordId, 'type' => $data['type']]);
            flash('success', 'DNS record saved. Publish to apply it to BIND.');
            redirect('zone', ['id' => $z['id']]);
        case 'delete_record':
            $z = requestZone();
            $recordId = intId($_POST['record_id'] ?? '0');
            if (!one('SELECT id FROM records WHERE id=? AND zone_id=?', [$recordId, $z['id']])) {
                throw new RuntimeException('Record was not found.', 404);
            }
            db()->beginTransaction();
            try {
                query('DELETE FROM records WHERE id=? AND zone_id=?', [$recordId, $z['id']]);
                query('UPDATE zones SET dirty=1,revision=revision+1 WHERE id=?', [$z['id']]);
                db()->commit();
            } catch (Throwable $error) {
                db()->rollBack();
                throw $error;
            }
            auditSafe('record.delete', (int) $z['id'], ['record_id' => $recordId]);
            flash('success', 'Record deleted. Publish to apply the change.');
            redirect('zone', ['id' => $z['id']]);
        case 'publish':
            requireAdmin();
            $count = (new Publisher(db()))->publish();
            auditSafe('bind.publish', null, ['zones' => $count]);
            flash('success', 'Validated, published, and reloaded ' . $count . ' DNS zones.');
            redirect('dashboard');
        case 'create_user':
            requireAdmin();
            $name = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? 'viewer');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/D', $name) ||
                strlen($password) < 14 || strlen($password) > 1024 ||
                !in_array($role, ['admin', 'editor', 'viewer'], true)) {
                throw new InvalidArgumentException('Enter a valid username, role, and password of at least 14 characters.');
            }
            query('INSERT INTO users (username,password_hash,role) VALUES (?,?,?)', [$name, password_hash($password, PASSWORD_DEFAULT), $role]);
            auditSafe('user.create', null, ['username' => $name, 'role' => $role]);
            flash('success', 'Team member created.');
            redirect('users');
        case 'update_user':
            requireAdmin();
            $target = intId($_POST['user_id'] ?? '0');
            $role = (string) ($_POST['role'] ?? '');
            if ($target === (int) $actor['id'] || !in_array($role, ['admin', 'editor', 'viewer'], true)) {
                throw new InvalidArgumentException('You cannot change your own role. Choose a valid role.');
            }
            if (!one('SELECT id FROM users WHERE id=?', [$target])) {
                throw new RuntimeException('User not found.', 404);
            }
            query('UPDATE users SET role=? WHERE id=?', [$role, $target]);
            auditSafe('user.role', null, ['user_id' => $target, 'role' => $role]);
            flash('success', 'Team member permissions updated.');
            redirect('users');
        case 'change_password':
            $current = (string) ($_POST['current_password'] ?? '');
            $replacement = (string) ($_POST['new_password'] ?? '');
            $profile = one('SELECT password_hash FROM users WHERE id=?', [$actor['id']]);
            if (!$profile || !password_verify($current, $profile['password_hash']) ||
                strlen($replacement) < 14 || strlen($replacement) > 1024) {
                throw new InvalidArgumentException('Current password is incorrect, or new password is shorter than 14 characters.');
            }
            query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($replacement, PASSWORD_DEFAULT), $actor['id']]);
            session_regenerate_id(true);
            auditSafe('user.password');
            flash('success', 'Password updated successfully.');
            redirect('profile');
        default:
            throw new InvalidArgumentException('Unsupported form action.');
    }
    throw new RuntimeException('Unexpected action completion.');
}

function zoneValues(array $input): array
{
    return [
        Dns::number($input['default_ttl'] ?? '3600', 60, 2147483647, 'Default TTL'),
        Dns::number($input['refresh'] ?? '10800', 60, 2147483647, 'Refresh'),
        Dns::number($input['retry'] ?? '3600', 60, 2147483647, 'Retry'),
        Dns::number($input['expire'] ?? '604800', 60, 2147483647, 'Expire'),
        Dns::number($input['minimum'] ?? '3600', 60, 2147483647, 'Minimum'),
    ];
}

function ownerChoices(): array
{
    $users = query("SELECT id,username FROM users WHERE role IN ('admin','editor') ORDER BY username")->fetchAll();
    $result = [];
    foreach ($users as $item) {
        $result[$item['id']] = $item['username'];
    }
    return $result;
}

function zoneFields(?array $zone = null): string
{
    $isNew = $zone === null;
    $owner = $zone['owner_id'] ?? user()['id'];
    $controls = '<div class="grid gap-5 md:grid-cols-2">' .
        ($isNew ? Ui::field('name', 'Zone name', '', 'text', 'For example: example.com') : '') .
        Ui::field('primary_ns', 'Primary nameserver', $zone['primary_ns'] ?? '', 'text', 'Fully qualified hostname, for example ns1.example.com') .
        Ui::field('contact', 'SOA contact name', $zone['contact'] ?? '', 'text', 'DNS mailbox notation, for example hostmaster.example.com') .
        (user()['role'] === 'admin' ? Ui::select('owner_id', 'Zone owner', ownerChoices(), $owner) : '') .
        Ui::field('default_ttl', 'Default TTL (seconds)', $zone['default_ttl'] ?? '3600', 'number') .
        Ui::field('refresh', 'SOA refresh (seconds)', $zone['refresh'] ?? '10800', 'number') .
        Ui::field('retry', 'SOA retry (seconds)', $zone['retry'] ?? '3600', 'number') .
        Ui::field('expire', 'SOA expire (seconds)', $zone['expire'] ?? '604800', 'number') .
        Ui::field('minimum', 'SOA minimum (seconds)', $zone['minimum'] ?? '3600', 'number') .
        '</div>';
    if (!$isNew) {
        $controls .= '<input type="hidden" name="zone_id" value="' . e($zone['id']) . '">';
    }
    $controls .= '<div class="mt-8 flex gap-3 flex-wrap"><button type="submit" class="btn-primary">' .
        ($isNew ? 'Create zone' : 'Save settings') . '</button>' .
        Ui::button('Cancel', route($isNew ? 'zones' : 'zone', $isNew ? [] : ['id' => $zone['id']]), true) . '</div>';
    return Ui::post($isNew ? 'create_zone' : 'update_zone', $controls);
}

function recordFields(array $zone, ?array $record = null): string
{
    $types = array_combine(Dns::TYPES, Dns::TYPES);
    $inputs = '<input type="hidden" name="zone_id" value="' . e($zone['id']) . '">' .
        ($record ? '<input type="hidden" name="record_id" value="' . e($record['id']) . '">' : '') .
        '<div class="grid gap-5 md:grid-cols-2">' .
        Ui::field('host', 'Host / record name', $record['host'] ?? '@', 'text', 'Use @ for the zone apex; _service._tcp for SRV') .
        Ui::select('type', 'Record type', $types, $record['type'] ?? 'A') .
        Ui::field('value', 'Record value', $record['value'] ?? '', 'text', 'IPv4/IPv6 address, hostname, or TXT content') .
        Ui::field('ttl', 'TTL (seconds)', $record['ttl'] ?? '3600', 'number') .
        Ui::field('priority', 'MX/SRV priority', $record['priority'] ?? 0, 'number') .
        Ui::field('weight', 'SRV weight', $record['weight'] ?? 0, 'number') .
        Ui::field('port', 'SRV port', $record['port'] ?? 0, 'number') .
        '</div><p class="mt-6 text-sm text-ink/60">Values are validated and normalized before saving. Publication validates the complete BIND zone before reloading DNS.</p>' .
        '<div class="mt-7 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Save record</button>' .
        Ui::button('Cancel', route('zone', ['id' => $zone['id']]), true) . '</div>';
    return Ui::post('save_record', $inputs);
}

function page(string $route): array
{
    $actor = requireUser();
    switch ($route) {
        case 'dashboard':
            $zones = (int) query('SELECT COUNT(*) FROM zones')->fetchColumn();
            $records = (int) query('SELECT COUNT(*) FROM records')->fetchColumn();
            $pending = (int) query('SELECT COUNT(*) FROM zones WHERE dirty=1')->fetchColumn();
            $stats = [
                ['value' => $zones, 'label' => 'Managed zones'],
                ['value' => $records, 'label' => 'DNS records'],
                ['value' => $pending, 'label' => 'Zones pending publication'],
            ];
            $metrics = '<div class="grid sm:grid-cols-3 border-y border-line mb-10">';
            foreach ($stats as $i => $stat) {
                $metrics .= '<div class="py-6 pr-7' . ($i ? ' sm:pl-7 sm:border-l border-line' : '') .
                    '"><p class="metric-value text-ink">' . $stat['value'] . '</p><p class="text-sm text-ink/55 mt-2">' .
                    e($stat['label']) . '</p></div>';
            }
            $metrics .= '</div>';
            $action = $actor['role'] === 'admin' ? Ui::button('Create DNS zone', route('zone-new')) : '';
            $body = Ui::heading('Good to see you, ' . $actor['username'], 'Your authoritative DNS workspace at a glance.', $action) . $metrics;
            if ($pending > 0) {
                $content = '<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-xl font-bold">Unpublished changes</h2>' .
                    '<p class="text-sm text-ink/65 mt-2">' . $pending . ' zones need validation and a BIND reload.</p></div>';
                $content .= $actor['role'] === 'admin' ?
                    Ui::post('publish', '<button class="btn-primary" type="submit">Validate &amp; publish DNS</button>', '', 'Publish all configured DNS zones and reload BIND?') :
                    '<p class="text-sm text-ink/65">An administrator must publish these changes.</p>';
                $body .= '<div class="surface p-6 border-l-4 border-l-amber-600 mb-8">' . $content . '</div>';
            }
            $recent = query('SELECT z.id,z.name,z.dirty,COUNT(r.id) AS record_count FROM zones z LEFT JOIN records r ON r.zone_id=z.id GROUP BY z.id,z.name,z.dirty ORDER BY z.updated_at DESC LIMIT 8')->fetchAll();
            $rows = '';
            foreach ($recent as $z) {
                $rows .= '<tr><td><a class="font-bold text-accent hover:underline" href="' . e(route('zone', ['id' => $z['id']])) .
                    '">' . e($z['name']) . '</a></td><td>' . (int) $z['record_count'] . '</td><td>' . Ui::status((bool) $z['dirty']) . '</td></tr>';
            }
            $body .= Ui::panel('Recently updated zones', $rows ? Ui::table(['Zone', 'Records', 'Status'], $rows) :
                Ui::empty('No DNS zones yet', 'Create your first zone to begin managing DNS.', $action),
                'Changes stay in the database until an administrator publishes them.');
            return ['Overview', $body];

        case 'zones':
            $rows = query('SELECT z.id,z.name,z.dirty,z.serial,u.username AS owner,COUNT(r.id) AS records FROM zones z JOIN users u ON u.id=z.owner_id LEFT JOIN records r ON r.zone_id=z.id GROUP BY z.id,z.name,z.dirty,z.serial,u.username ORDER BY z.name')->fetchAll();
            $html = '';
            foreach ($rows as $z) {
                $html .= '<tr><td><a class="font-bold text-accent hover:underline" href="' . e(route('zone', ['id' => $z['id']])) . '">' .
                    e($z['name']) . '</a></td><td>' . e($z['owner']) . '</td><td>' . (int) $z['records'] .
                    '</td><td class="font-mono text-xs">' . e($z['serial']) . '</td><td>' . Ui::status((bool) $z['dirty']) . '</td></tr>';
            }
            $new = $actor['role'] === 'admin' ? Ui::button('+ New zone', route('zone-new')) : '';
            return ['DNS zones', Ui::heading('DNS zones', 'All zones managed by this SMBind instance.', $new) .
                ($html ? '<div class="surface">' .
                    '<div class="border-b border-line bg-white px-5 py-4"><label class="flex max-w-lg flex-col gap-2 text-sm font-semibold text-ink" for="zone-search">' .
                    '<span>Filter DNS zones</span><input class="field-input" id="zone-search" type="search" autocomplete="off" placeholder="Search names, owners or status" data-table-search="zone-results" aria-controls="zone-results"></label></div>' .
                    '<div id="zone-results">' . Ui::table(['Zone', 'Owner', 'Records', 'SOA serial', 'Status'], $html) . '</div>' .
                    '<p class="hidden p-6 text-sm text-ink/65" id="zone-search-empty" role="status">No zones match your search.</p></div>' :
                    Ui::empty('Your zone inventory is empty', 'Add a zone, create its records, and publish the configuration.', $new))];

        case 'zone-new':
            requireAdmin();
            return ['New DNS zone', Ui::heading('Create DNS zone', 'Configure authoritative DNS settings before adding records.') .
                Ui::panel('Zone settings', zoneFields())];

        case 'zone':
            $z = zone(intId($_GET['id'] ?? '0'));
            $editable = canEdit($z);
            $records = query('SELECT * FROM records WHERE zone_id=? ORDER BY host,type,id', [$z['id']])->fetchAll();
            $new = $editable ? Ui::button('+ Add record', route('record-new', ['zone' => $z['id']])) : '';
            $rows = '';
            foreach ($records as $r) {
                $cell = $editable ? '<a class="font-bold text-accent hover:underline" href="' . e(route('record-edit', ['id' => $r['id']])) . '">' .
                    e($r['host']) . '</a>' : e($r['host']);
                $delete = $editable ? Ui::post(
                    'delete_record',
                    '<input type="hidden" name="zone_id" value="' . e($z['id']) .
                    '"><input type="hidden" name="record_id" value="' . e($r['id']) .
                    '"><button class="font-bold text-warn hover:underline text-sm" type="submit">Delete</button>',
                    '', 'Permanently remove this DNS record?'
                ) : '';
                $rows .= '<tr><td>' . $cell . '</td><td><span class="font-mono text-xs font-bold">' . e($r['type']) .
                    '</span></td><td class="max-w-sm break-all">' . e($r['value']) . '</td><td>' . e($r['ttl']) .
                    '</td><td>' . e($r['priority']) . '</td><td>' . $delete . '</td></tr>';
            }
            $body = Ui::heading($z['name'], 'Zone owner: ' . $z['owner_name'] . ' · Serial ' . $z['serial'],
                Ui::button('All zones', route('zones'), true)) .
                '<div class="mb-7 flex flex-wrap gap-4 items-center">' . Ui::status((bool) $z['dirty']) .
                ($editable ? Ui::button('Edit zone settings', route('zone-edit', ['id' => $z['id']]), true) : '') . '</div>' .
                Ui::panel('Resource records', $rows ? Ui::table(['Host', 'Type', 'Value', 'TTL', 'Priority', ''], $rows) :
                    Ui::empty('No custom records', 'The zone already includes its SOA and primary NS records.', $new),
                    'Every edit requires publication before BIND serves it.');
            $body .= $rows && $new ? '<div class="mt-5">' . $new . '</div>' : '';
            if ($actor['role'] === 'admin') {
                $body .= '<div class="mt-12 border-t border-line pt-7"><h2 class="font-bold text-warn">Danger zone</h2><p class="text-sm text-ink/60 mt-1 mb-4">Deletion is permanent. Publishing will remove the zone from BIND configuration.</p>' .
                    Ui::post('delete_zone', '<input type="hidden" name="zone_id" value="' . e($z['id']) .
                    '"><button class="btn-danger" type="submit">Delete zone permanently</button>', '',
                    'Delete ' . $z['name'] . ' and all of its records? This cannot be undone.') . '</div>';
            }
            return [$z['name'], $body];

        case 'zone-edit':
            $z = zone(intId($_GET['id'] ?? '0'));
            if (!canEdit($z)) {
                throw new RuntimeException('Access denied.', 403);
            }
            return ['Edit zone', Ui::heading('Edit ' . $z['name'], 'Modify SOA options, nameserver settings, and ownership.') .
                Ui::panel('Zone configuration', zoneFields($z))];

        case 'record-new':
            $z = zone(intId($_GET['zone'] ?? '0'));
            if (!canEdit($z)) {
                throw new RuntimeException('Access denied.', 403);
            }
            return ['Add DNS record', Ui::heading('Add DNS record', 'Zone: ' . $z['name']) .
                Ui::panel('Record settings', recordFields($z))];

        case 'record-edit':
            $record = one('SELECT * FROM records WHERE id=?', [intId($_GET['id'] ?? '0')]);
            if (!$record) {
                throw new RuntimeException('DNS record not found.', 404);
            }
            $z = zone((int) $record['zone_id']);
            if (!canEdit($z)) {
                throw new RuntimeException('Access denied.', 403);
            }
            return ['Edit DNS record', Ui::heading('Edit DNS record', 'Zone: ' . $z['name']) .
                Ui::panel('Record settings', recordFields($z, $record))];

        case 'users':
            requireAdmin();
            $items = query('SELECT id,username,role,created_at FROM users ORDER BY username')->fetchAll();
            $rows = '';
            foreach ($items as $item) {
                $roleForm = $item['id'] == $actor['id'] ? '<span class="text-xs text-ink/55">Current user</span>' :
                    Ui::post('update_user', '<input type="hidden" name="user_id" value="' . e($item['id']) . '">' .
                        '<div class="flex gap-2 items-center">' .
                        Ui::select('role', 'Role', ['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'], $item['role']) .
                        '<button type="submit" class="btn-secondary">Save</button></div>');
                $rows .= '<tr><td class="font-bold">' . e($item['username']) . '</td><td>' . e($item['role']) .
                    '</td><td>' . e($item['created_at']) . '</td><td>' . $roleForm . '</td></tr>';
            }
            $fields = '<div class="grid md:grid-cols-3 gap-5">' .
                Ui::field('username', 'Username', '', 'text') .
                Ui::field('password', 'Initial password', '', 'password', 'At least 14 characters') .
                Ui::select('role', 'Access level', ['viewer' => 'Viewer', 'editor' => 'Editor', 'admin' => 'Administrator'], 'viewer') .
                '</div><button class="btn-primary mt-6" type="submit">Create team member</button>';
            return ['Team access', Ui::heading('Team access', 'Administrators publish DNS; editors manage their assigned zones; viewers have read-only access.') .
                '<div class="surface mb-9">' . Ui::table(['Username', 'Role', 'Joined', 'Manage'], $rows) . '</div>' .
                Ui::panel('Add team member', Ui::post('create_user', $fields))];

        case 'activity':
            requireAdmin();
            $items = query('SELECT a.action,a.detail,a.ip_address,a.created_at,u.username FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
            $rows = '';
            foreach ($items as $item) {
                $rows .= '<tr><td class="font-mono text-xs font-bold">' . e($item['action']) . '</td><td>' .
                    e($item['username'] ?? 'System') . '</td><td>' . e($item['ip_address']) . '</td><td>' .
                    e($item['created_at']) . '</td></tr>';
            }
            return ['Activity log', Ui::heading('Activity log', 'Recent user and publication events; newest first.') .
                ($rows ? '<div class="surface">' . Ui::table(['Event', 'User', 'IP', 'Time (UTC)'], $rows) . '</div>' :
                    Ui::empty('No activity yet', 'Actions will appear here once performed.'))];

        case 'profile':
            $fields = Ui::field('current_password', 'Current password', '', 'password') .
                '<div class="mt-5">' . Ui::field('new_password', 'New password', '', 'password', 'Minimum 14 characters') .
                '</div><button type="submit" class="btn-primary mt-7">Change password</button>';
            return ['My account', Ui::heading('My account', 'Signed in as ' . $actor['username'] . ' · ' . ucfirst($actor['role'])) .
                Ui::panel('Change password', Ui::post('change_password', $fields))];

        default:
            throw new RuntimeException('Page not found.', 404);
    }
}

try {
    $route = (string) ($_GET['route'] ?? 'dashboard');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handlePost();
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new RuntimeException('HTTP method is not supported.', 405);
    }
    if (user()) {
        $verified = one('SELECT id,username,role FROM users WHERE id=?', [user()['id']]);
        if (!$verified) {
            unset($_SESSION['user']);
        } else {
            $_SESSION['user'] = [
                'id' => (int) $verified['id'], 'username' => $verified['username'], 'role' => $verified['role'],
            ];
        }
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if ($route === 'login' && !user()) {
        $logo = '<div class="mb-9 flex items-center gap-3"><span class="brand-mark">S<span>/</span></span><span class="font-extrabold text-2xl tracking-tight">SMBind</span></div>';
        $form = '<div class="surface p-8"><h1 class="text-3xl font-bold tracking-tight mb-2">Welcome back</h1>' .
            '<p class="text-ink/55 text-sm mb-7">Sign in to your authoritative DNS workspace.</p>' .
            Ui::post('login', Ui::field('username', 'Username', '', 'text') .
                '<div class="mt-5">' . Ui::field('password', 'Password', '', 'password') .
                '</div><button type="submit" class="btn-primary mt-7 w-full">Sign in</button>') .
            '</div><p class="text-xs text-ink/50 mt-6">SMBind 1.0 · Secured management console</p>';
        Ui::layout('Sign in', $logo . $form, null, $flash);
        exit;
    }
    if ($route === 'login' && user()) {
        redirect('dashboard');
    }
    [$title, $body] = page($route);
    Ui::layout($title, $body, user(), $flash);
} catch (Throwable $error) {
    $code = $error instanceof InvalidArgumentException ? 422 : (in_array($error->getCode(), [403, 404, 405], true) ? $error->getCode() : 500);
    http_response_code($code);
    $message = $code === 500 ? 'An unexpected error occurred. Check server logs for details.' : $error->getMessage();
    if ($code === 500) {
        error_log('SMBind: ' . $error);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code !== 500) {
        flash('error', $message);
        $target = user() ? 'dashboard' : 'login';
        if (user() && isset($_POST['zone_id'])) {
            $target = 'zone';
            $query = ['id' => (int) $_POST['zone_id']];
        } else {
            $query = [];
        }
        redirect($target, $query);
    }
    $body = Ui::heading($code === 500 ? 'Service unavailable' : (string) $code, $message) .
        Ui::button('Return to overview', route(user() ? 'dashboard' : 'login'), true);
    Ui::layout('Error ' . $code, $body, user());
}
