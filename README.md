# use BootPress\SQLite\Component as Sqlite;

[![Packagist][badge-version]][link-packagist]
[![License MIT][badge-license]](LICENSE.md)
[![HHVM Tested][badge-hhvm]][link-travis]
[![PHP 7 Supported][badge-php]][link-travis]
[![Build Status][badge-travis]][link-travis]
[![Code Climate][badge-code-climate]][link-code-climate]
[![Test Coverage][badge-coverage]][link-coverage]

Extends the BootPress\Database\Component to easily create and update SQLite database tables and indexes at will.  It overrides the underlying PDO wrappers of the Database Component to use the PHP SQLite3 class.  The main reason is so that you can free the file from it's cold dead hands when you ``$db->connection()->close()``.  The only side effect of that is you can't fetch 'obj' or 'named' rows.  Otherwise, we are just adding more functionality here.  It also facilitates FTS full-text searching.

## Installation

Add the following to your ``composer.json`` file.

``` bash
{
    "require": {
        "bootpress/sqlite": "^1.0"
    }
}
```

## Example Usage

``` php
<?php

use BootPress\SQLite\Component as Sqlite;

$db = new Sqlite; // An in-memory database

if ($db->created) {

    $db->settings('version', '1.0');
    
    $db->create('employees', array(
        'id' => 'INTEGER PRIMARY KEY',
        'name' => 'TEXT COLLATE NOCASE',
        'position' => 'TEXT NOT NULL DEFAULT ""',
    ), array('unique'=>'position'));
    
    // Wait, I just changed my mind:
    $db->create('employees', array(
        'id' => 'INTEGER PRIMARY KEY',
        'name' => 'TEXT UNIQUE COLLATE NOCASE',
        'title' => 'TEXT DEFAULT ""',
    ), 'title', array(
        'position' => 'title',
    ));
    
    $db->fts->create('results', 'search');
    
    // You can insert, update, and query an FTS table the same as any other.
    if ($stmt = $db->insert('results', array('docid', 'search'))) {
        $db->insert($stmt, array(100, 'Fisherman never die, they just get reel tired.'));
        $db->insert($stmt, array(101, 'If wishes were fishes, we\'d have a fish fry.'));
        $db->insert($stmt, array(102, 'Women want me, fish fear me.'));
        $db->insert($stmt, array(103, 'Good things come to those who bait.'));
        $db->insert($stmt, array(104, 'A reel expert can tackle anything.'));
    }
    
}

echo $db->settings('version'); // 1.0

echo $db->fts->count('results', 'fish')); // 2

print_r($db->fts->search('results', 'fish'));
/*
array(
    array(
        'docid' => 101,
        'snippet' => "If wishes were <b>fishes</b>, we'd have a <b>fish</b> fry.",
        'offsets' => '0 0 15 6 0 0 35 4',
        'rank' => 1.333,
    ),
    array(
        'docid' => 102,
        'snippet' => 'Women want me, <b>fish</b> fear me.',
        'offsets' => '0 0 15 4',
        'rank' => .666,
    ),
)
*/

echo implode(', ', $db->fts->words('results', 'fish', 101)); // fishes, fish
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[badge-version]: https://img.shields.io/packagist/v/bootpress/sqlite.svg?style=flat-square&label=Packagist
[badge-license]: https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square
[badge-hhvm]: https://img.shields.io/badge/HHVM-Tested-8892bf.svg?style=flat-square
[badge-php]: https://img.shields.io/badge/PHP%207-Supported-8892bf.svg?style=flat-square
[badge-travis]: https://img.shields.io/travis/Kylob/SQLite/master.svg?style=flat-square
[badge-code-climate]: https://img.shields.io/codeclimate/github/Kylob/SQLite.svg?style=flat-square
[badge-coverage]: https://img.shields.io/codeclimate/coverage/github/Kylob/SQLite.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/bootpress/sqlite
[link-travis]: https://travis-ci.org/Kylob/SQLite
[link-code-climate]: https://codeclimate.com/github/Kylob/SQLite
[link-coverage]: https://codeclimate.com/github/Kylob/SQLite/coverage
