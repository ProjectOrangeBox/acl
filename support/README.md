# ACL schema

MySQL/InnoDB DDL for the six tables `orange/acl` reads and writes. The names are
fixed — they are constants on `orange\acl\dtos\AclTables`, not configuration; see
the package README for what using different ones takes.

## Load order matters

The join tables carry foreign keys, so the tables they point at have to exist
first. Alphabetical order (what `cat *.sql` or a glob gives you) does **not**
work: `orange_role_permission` sorts before `orange_roles`.

Load them in this order:

```sh
mysql db < orange_users.sql
mysql db < orange_roles.sql
mysql db < orange_permissions.sql
mysql db < orange_user_meta.sql        # -> orange_users
mysql db < orange_user_role.sql        # -> orange_users, orange_roles
mysql db < orange_role_permission.sql  # -> orange_roles, orange_permissions
```

The three independent tables first, then the three that reference them.

## What the constraints are for

Every one of them backs a check the application already makes, rather than
replacing it:

- **UNIQUE** on `orange_users.email` / `.username`, `orange_roles.name` and
  `orange_permissions.key`. The models refuse a duplicate before writing, but
  that check and the write are two statements and a concurrent create can slip
  between them. The constraint is what actually prevents the duplicate; the check
  exists so the ordinary case reports a readable per-field error instead of a
  driver-level integrity violation.
- **FOREIGN KEY ... ON DELETE CASCADE** on the join tables and the meta table. The
  models already clean up their own links in a transaction; these make an orphaned
  grant impossible from outside them too. An orphan is not merely untidy — a row
  granting a role that no longer exists points at whatever later takes that id.
- **Secondary indexes** on the join tables' second column. The composite primary
  key cannot serve a lookup by the trailing column, which is exactly what
  deleting a role or a permission does.

Note the user uniqueness spans soft-deleted rows on purpose: a deleted account
keeps its email and username reserved, so neither can be claimed by someone else
and quietly inherit anything still keyed to them.
