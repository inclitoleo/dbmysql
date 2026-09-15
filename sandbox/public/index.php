<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$builder = dbmysql_lab_builder();
$flash = '';
$error = '';

try {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $id = $builder->insert('account', ['name' => $name, 'email' => $email]);
        $flash = "Created account id {$id}.";
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $builder->update('account', ['name' => $name, 'email' => $email], ['id' => $id]);
        $flash = "Updated account id {$id}.";
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $builder->delete('account', ['id' => $id]);
        $flash = "Deleted account id {$id}.";
    }
} catch (\Inclitoleo\Mysql\Exception\MysqlException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = '{"code":500}';
}

$accounts = $builder->from('account')->select(['id', 'name', 'email'])->get();
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>dbmysql CRUD lab</title>
    <style>
        body { font-family: sans-serif; margin: 2rem; max-width: 720px; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: 0.4rem 0.6rem; text-align: left; }
        .ok { color: #0a0; }
        .err { color: #a00; }
        form.inline { display: inline; }
        input { margin: 0.2rem 0.2rem 0.2rem 0; }
    </style>
</head>
<body>
<h1>dbmysql CRUD lab</h1>
<p>Pacote instalado via Composer em <code>vendor/inclitoleo/dbmysql</code>. Debug da lib: <code>DBMYSQL_DEBUG=0</code> (JSON <code>{"code":500}</code>); <code>1</code> mostra o SQLSTATE cru.</p>
<?php if ($flash !== ''): ?><p class="ok"><?= $h($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>

<h2>Create</h2>
<form method="post">
    <input type="hidden" name="action" value="create">
    <input name="name" placeholder="name" required>
    <input name="email" type="email" placeholder="email" required>
    <button type="submit">Insert</button>
</form>

<h2>Read / Update / Delete</h2>
<table>
    <thead><tr><th>id</th><th>name</th><th>email</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $row): ?>
        <tr>
            <td><?= (int) $row->id ?></td>
            <td colspan="3">
                <form method="post" class="inline">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $row->id ?>">
                    <input name="name" value="<?= $h((string) $row->name) ?>">
                    <input name="email" value="<?= $h((string) $row->email) ?>">
                    <button type="submit">Update</button>
                </form>
                <form method="post" class="inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row->id ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
