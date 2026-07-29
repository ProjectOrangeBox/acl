<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use orange\model\DtoModel;
use orange\model\exceptions\DtoValidationFailed;

/**
 * What the ACL models share once their rules moved into Dtos: a uniqueness
 * check, and the exception shape it reports through.
 *
 * Uniqueness is the one rule that could not become an attribute. A Dto is
 * constructed as `new $dtoClass($input)` with no database handle - that is what
 * makes it cheap, testable and reusable outside a request - so "is this email
 * already taken" has nowhere to live inside one and belongs to the model, which
 * does have the connection.
 *
 * @method static static getInstance(array $config, PDO $pdo)
 * @method static static newInstance(array $config, PDO $pdo)
 */
abstract class AclModel extends DtoModel
{
    /**
     * Reject values already present in another row.
     *
     * A friendly, field-keyed failure that reads exactly like a Dto's own - the
     * caller cannot tell which layer rejected the input, which is the point.
     *
     * This is a check, not a guarantee: another connection can insert a
     * conflicting row between this SELECT and the write that follows. The
     * table's UNIQUE index is what actually prevents the duplicate; this exists
     * so the ordinary case reports a readable error per field instead of a
     * driver-level integrity violation. Both are needed - neither alone gives
     * correctness and a good message.
     *
     * @param array<string, mixed> $columns The validated columns about to be written
     * @param array<string, string> $labels Column name => human label, for the message
     * @param int|null $ignoreId Row to exclude - the record being updated, which
     *        is allowed to keep its own value
     *
     * @throws DtoValidationFailed When any of them is taken
     */
    protected function ensureUnique(array $columns, array $labels, ?int $ignoreId = null): void
    {
        $errors = [];
        // the assembled config, not the raw property - a config override is the
        // table Sql was built with
        $primary = (string)$this->config['primaryColumn'];
        $tablename = (string)$this->config['tablename'];

        foreach ($labels as $column => $label) {
            // an operation that doesn't write the column can't collide on it
            if (!array_key_exists($column, $columns)) {
                continue;
            }

            // Both of these are stated rather than inherited because the Sql
            // instance is shared and keeps its state between queries: read()
            // leaves it in entity fetch mode, and getRolesPermissions() leaves
            // it pointing at the user/role join table - select() resets the
            // columns and where, but not the table.
            $query = $this->sql
                ->setFetchMode(PDO::FETCH_ASSOC)
                ->select($primary)
                ->from($tablename)
                ->where($column, '=', $columns[$column]);

            if ($ignoreId !== null) {
                $query->and()->where($primary, '!=', $ignoreId);
            }

            if ($query->limit(1)->execute()->column() !== false) {
                $errors[$column] = [$label . ' is already in use.'];
            }
        }

        if ($errors !== []) {
            throw new DtoValidationFailed($this->errorsAsText($errors), $errors);
        }
    }
}
