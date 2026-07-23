<?php

// defaults for the session-aware User helper (orange\acl\User)
return [
    // must match 'guest user' in acl.php - the id User falls back to when
    // no one is logged in
    'guest user' => 2,
    // session key the current user id is stored under
    'sessionKey' => '##user##session##',
];
