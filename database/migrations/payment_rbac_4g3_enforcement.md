# Batch 4G-3 deployment order

1. Back up Core and run the read-only preflight.
2. Resolve duplicate and canonical/alias collisions manually. A stored revocation is authoritative.
3. Confirm a unique `(role_key,module_key)` index.
4. Apply `payment_rbac_4g3_enforcement.sql` while the old application is still running. It creates explicit decisions for new sensitive keys and preserves existing rows.
5. Deploy the application code only after the migration commits.
6. Validate four isolated staff accounts and centralized audit delivery.

Do not deploy code first: new concern, activation, and export permissions fail closed when their explicit rows are absent. Rollback SQL is safe only before administrators edit the new keys.