# Phase 5B Catalog Audit Event Matrix

One committed catalog mutation produces one immutable outbox event. `before_state` and `after_state` are canonical JSON snapshots sufficient to explain the change; secrets and unrelated personal data are excluded. Every row carries correlation ID, fingerprint, actor snapshot, and committed result.

| Mutation | `action` | `entity_type` | Before | After | Notes |
|---|---|---|---|---|---|
| Create item | `catalog.item.created` | `school_sale_item` | null | Full created item | Initial status must not bypass activation guard |
| Update item | `catalog.item.updated` | `school_sale_item` | Prior item | Updated item | Include changed fields |
| Change item status | `catalog.item.status_changed` | `school_sale_item` | Prior lifecycle | New lifecycle | Activation records guard outcome context |
| Create variant | `catalog.variant.created` | `school_sale_item_variant` | null | Full variant | No sale enabled |
| Update variant | `catalog.variant.updated` | `school_sale_item_variant` | Prior variant | Updated variant | Includes lifecycle changes |
| Activate/deactivate variant | `catalog.variant.status_changed` | `school_sale_item_variant` | Prior lifecycle | New lifecycle | Item validity must be re-evaluated |
| Create price draft | `catalog.price.created` | `school_sale_variant_price` | null | Full price/window | Amount remains server-authoritative |
| Activate price | `catalog.price.activated` | `school_sale_variant_price` | Prior lifecycle | Active price/window | Reject overlap |
| Close/retire price | `catalog.price.retired` | `school_sale_variant_price` | Prior price/window | Closed price/window | No in-place amount rewrite |
| Add applicability | `catalog.applicability.created` | `school_sale_item_applicability` | null | Assignment | Only catalog eligibility |
| Deactivate applicability | `catalog.applicability.deactivated` | `school_sale_item_applicability` | Active assignment | Inactive assignment | Preserve history |
| Change mode to RESTRICTED | `catalog.applicability.restricted` | `school_sale_item` | Prior mode/scopes | New mode/scopes | Requires at least one Active assignment |
| Change mode to ALL | `catalog.applicability.all` | `school_sale_item` | Prior mode/scopes | ALL + deactivated scopes | One aggregate event may summarize all row deactivations |

Failed validation attempts do not create committed catalog audit events because no mutation commits. Security/request logs may record them separately in existing facilities. Idempotent replay returns the existing event/result and must not enqueue a duplicate.

Core projection uses the existing `activity_logs` fields: action, module, details, user/role/IP/user-agent, entity type/id, before/after state, and correlation ID. The later delivery implementation must map field sizes explicitly and reject silent truncation.
