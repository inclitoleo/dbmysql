<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

$builder = dbmysql_lab_builder();
$stamp = date('His');
$email = "lab-{$stamp}@example.com";

echo "Package: inclitoleo/dbmysql (Composer vendor install)\n";
echo "CRUD lab against table account\n\n";

$id = $builder->insert('account', [
    'name' => 'Lab User ' . $stamp,
    'email' => $email,
]);
echo "CREATE  insert id={$id} email={$email}\n";

$created = $builder->from('account')->where('id', '=', $id)->first();
echo 'READ    ' . json_encode($created, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$updated = $builder->update('account', ['name' => 'Lab User Updated'], ['id' => $id]);
$after = $builder->from('account')->where('id', '=', $id)->first();
echo "UPDATE  rows={$updated} name={$after->name}\n";

$list = $builder->from('account')->select(['id', 'name', 'email'])->limit(20)->get();
echo 'LIST    ' . count($list) . " row(s)\n";

$deleted = $builder->delete('account', ['id' => $id]);
$gone = $builder->from('account')->where('id', '=', $id)->first();
echo 'DELETE  rows=' . $deleted . ' remaining=' . ($gone === null ? 'none' : 'still present') . PHP_EOL;

echo "\nCRUD complete.\n";
