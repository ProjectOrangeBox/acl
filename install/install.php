<?php

declare(strict_types=1);

/**
 * What `vendor/bin/installModule orange/acl` needs to know beyond the files.
 *
 * The directory tree beside this file says where everything goes - position is
 * the instruction. This says only what a directory cannot: what has to be true
 * for the files to be worth copying, and what is still left to do once they
 * are. A migration that has been copied but not run has installed nothing the
 * user can see, and only this package knows that db:migrate is the next step.
 */

return [
    'name' => 'orange/acl',

    // the models talk to MySQL through PDO; there is no other adapter
    'requires' => ['pdo_mysql'],

    'php' => '8.4',

    'after' => [
        'composer db:migrate                 create the six acl tables',
        'composer db:seed -s OrangeAclSeeder create the guest user row acl requires',
        '',
        'The guest row is not optional - User::load() resolves every request',
        'without a login to it. Accounts, roles and permissions are yours to',
        'seed; support/seed.sql in this package is an example to copy from.',
    ],
];
