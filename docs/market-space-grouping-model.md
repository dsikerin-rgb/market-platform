# Market space grouping model

Status: product and technical rule for grouped spaces, map colouring, dashboard statistics, and future shared-space work.

## 1. Core model

Market Platform has three different concepts that must not be mixed:

1. Accounting space
2. Physical map segment
3. Shared-use place

### Accounting space

An accounting space is the object that can be visible to 1C, the director spreadsheet, contracts, accruals and debt logic.

Accounting spaces are:

- ordinary spaces: `space_group_role = none` or empty;
- parent group spaces: `space_group_role = parent`;
- legacy orphan children without `space_group_parent_id`, until they are repaired.

### Physical map segment

A physical map segment is a shape-level part of a space on the map.

For grouped spaces this is represented by child spaces:

- `space_group_role = child`;
- `space_group_parent_id` points to the parent group;
- `space_group_slot` identifies the segment inside the group.

A child with a parent is not an independent accounting space.

### Shared-use place

A shared-use place is one physical/accounting space used by several tenants or payers at the same time.

Example: `Склад 21` in the director spreadsheet, where one department number has several tenant rows and area/payment lines.

This is not a parent/child group. It needs a separate future model for participants/shares.

## 2. Parent group rule

A parent group is the accounting unit for 1C, the director spreadsheet, contracts, accruals and debt.

A parent group should not have an ordinary active trading shape on the map.

The group is displayed on the map through its child shapes. If a visual group outline is needed later, it must be a separate overlay/outline concept, not an ordinary `market_space_map_shapes.market_space_id = parent_id` trading shape.

## 3. Child inheritance rule

A child with a parent must inherit operational and financial context from its parent group.

For a child with `space_group_parent_id`:

- tenant is inherited from the parent;
- occupancy is inherited from the parent;
- debt colour is inherited from the parent;
- contract/1C context is inherited from the parent.

A child with its own `tenant_id` is a data-quality warning, not a separate business truth.

## 4. Dashboard statistics rule

Dashboard counters that represent trading/accounting places must count accounting spaces only.

Include:

- ordinary spaces;
- parent groups;
- legacy orphan children without a parent, until they are repaired.

Exclude:

- child spaces that have a parent.

Reason: child spaces are physical segments, not separate accounting places visible to 1C or the director spreadsheet.

Recommended dashboard interpretation:

- `Торговых мест` = accounting spaces;
- `Занято мест` = occupied accounting spaces;
- `Свободно мест` = vacant accounting spaces;
- `Заполняемость` = occupied accounting spaces / total accounting spaces.

A separate future metric may show physical map segments if needed.

## 5. Map colouring rule

The map colour must follow the accounting/debt owner.

### Ordinary space

Colour by the ordinary space debt status.

### Parent group

Colour by the parent group debt status.

### Child space

Colour by inherited parent debt status.

The popup must clearly state that the tenant/debt status is inherited from the parent group.

### Shared-use place

Colour by aggregated status across all participants.

Default aggregation rule: worst participant status wins.

The popup must show participants and their individual statuses/area/payment context when the shared-space model exists.

## 6. Shared-space future model

Shared-use places need a separate model, not parent/child grouping.

Possible future table:

`market_space_participants` or `market_space_tenant_shares`

Suggested fields:

- `market_id`;
- `market_space_id`;
- `tenant_id`;
- `area_sqm` nullable;
- `share_percent` nullable;
- `period_start` nullable;
- `period_end` nullable;
- `source` such as `1c`, `director_sheet`, `manual`;
- timestamps.

This future work requires a separate migration, UI, import logic and debt aggregation logic.

## 7. Implementation sequence

1. Fix dashboard accounting counters so child segments with a parent are excluded.
2. Make child occupancy/debt inheritance strict.
3. Add map warnings for parent groups with ordinary active shapes.
4. Adjust child popups to show inherited parent tenant/debt context.
5. Design shared-use places separately.

## 8. Non-goals for the first implementation

Do not in the first small fix:

- create shared-space tables;
- migrate production data;
- delete parent shapes automatically;
- change 1C bindings;
- change contracts/accruals/debt imports;
- run migrations.
