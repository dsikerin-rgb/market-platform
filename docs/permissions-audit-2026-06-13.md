# Аудит Permissions, 2026-06-13

## Область проверки

Проверены текущие правила доступа для админ-панели, ресурсов Filament, кабинета арендатора, кастомных admin routes и входящих API 1C.

Проверка статическая: код прочитан в отдельном worktree `codex/permissions-audit` от `origin/main`, без изменения бизнес-логики и без деплоя.

## Главный вывод

Критичной дыры вида "любой гость видит данные" не найдено. Самая важная проблема другая: в админке смешаны два разных понятия:

- пользователь может войти в admin panel;
- пользователь может управлять операционными, финансовыми и справочными данными рынка.

Сейчас вход в админку разрешен широкому набору внутренних ролей, а часть ресурсов после этого проверяет только наличие `market_id`. Это дает слишком широкие права в пределах своего рынка для ролей, которые по смыслу должны иметь более узкий доступ.

## Модель доступа сейчас

### Admin panel

`User::canAccessPanel()` пускает в админку пользователя, если `AdminPanelImpersonation::hasAdminPanelRole()` вернул true.

Admin роли сейчас включают не только `super-admin`, `market-admin`, `market-manager`, `market-operator`, но и внутренние роли: `market-maintenance`, `market-engineer`, `market-it`, `market-accountant`, `market-finance`, `market-marketing`, `market-advertising`, `market-support`, `market-security`, `market-guard`, `market-hr`, `staff`.

Файлы:

- `app/Models/User.php:186`
- `app/Support/AdminPanelImpersonation.php:46`

### Роли и permissions

Seeder создает роли и permissions, но большинство внутренних ролей не получают явных permissions. При этом они все равно могут войти в админку, если имеют роль из списка admin panel.

`market-admin` получает `market-settings.*`, `marketplace.*`, `staff.*`, `contracts.update`, но не получает `markets.*`.

Файл: `database/seeders/RolesAndPermissionsSeeder.php:20`

### Кабинет арендатора

Кабинет защищен лучше: доступ идет через `cabinet.access`, проверяется роль `merchant` / `merchant-user`, `tenant_id`, а в контроллерах данные фильтруются по `tenant_id`, `market_id` и, где нужно, по разрешенным местам сотрудника арендатора.

Проверенные зоны: документы, товары, обращения, места, начисления, чат покупателей, витрина.

Файлы:

- `app/Http/Middleware/EnsureTenantCabinetAccess.php`
- `app/Services/Cabinet/PortalAccessService.php`
- `app/Http/Controllers/Cabinet/*Controller.php`

### API 1C

Входящие endpoints 1C принимают Bearer token, ищут активную `MarketIntegration` типа `1c`, и привязывают импорт к `market_id` этой интеграции. Запрос без токена или с неактивным токеном получает 401.

Проверенные контроллеры: contracts, contract debts, accruals, payments, settlements.

Файлы:

- `app/Http/Controllers/Api/OneC/ContractController.php`
- `app/Http/Controllers/Api/OneC/ContractDebtController.php`
- `app/Http/Controllers/Api/OneC/AccrualController.php`
- `app/Http/Controllers/Api/OneC/PaymentController.php`
- `app/Http/Controllers/Api/OneC/SettlementController.php`

## Находки

### P1. Слишком широкие права на места и арендаторов внутри рынка

`MarketSpaceResource` и `TenantResource` разрешают просмотр, создание и редактирование любому пользователю, который уже попал в админку и имеет `market_id`.

Это означает, что роли вроде `market-maintenance`, `market-security`, `market-guard`, `market-marketing`, `market-hr`, `staff` потенциально могут редактировать места и арендаторов своего рынка, хотя это не похоже на ожидаемую модель прав.

Файлы:

- `app/Filament/Resources/MarketSpaceResource.php:3587`
- `app/Filament/Resources/MarketSpaceResource.php:3594`
- `app/Filament/Resources/MarketSpaceResource.php:3601`
- `app/Filament/Resources/TenantResource.php:4183`
- `app/Filament/Resources/TenantResource.php:4190`
- `app/Filament/Resources/TenantResource.php:4197`

Рекомендация: ввести явные capability-проверки вместо `(bool) $user->market_id`, например:

- `canManageMarketSpaces`
- `canViewMarketSpaces`
- `canManageTenants`
- `canViewTenants`

Минимальный первый шаг: оставить управление местами и арендаторами для `super-admin`, `market-admin`, возможно `market-manager`; остальные роли переводить на read-only или закрывать ресурс.

### P1. Финансовые страницы 1C доступны всем admin-panel ролям с market_id

Страницы 1C и отчетов проверяют только `super-admin || market_id`. Это значит, что любой внутренний admin-panel пользователь своего рынка может видеть финансовую информацию 1C.

Файлы:

- `app/Filament/Pages/OneCReconciliation.php:49`
- `app/Filament/Pages/OneCSettlements.php:60`
- `app/Filament/Pages/OneCDebtDecisionPreview.php:57`
- `app/Filament/Pages/ReportsHub.php:64`
- `app/Filament/Resources/TenantAccruals/TenantAccrualResource.php:169`
- `app/Filament/Resources/TenantAccruals/TenantAccrualResource.php:181`

Рекомендация: выделить отдельные права:

- `finance.1c.view`
- `finance.1c.reconcile`
- `finance.accruals.view`

По ролям по умолчанию доступ давать `super-admin`, `market-admin`, `market-manager`, `market-accountant`, `market-finance`. Для технических, охраны, маркетинга и персонала - закрыть.

### P2. Несогласованность permission names для настроек рынка

Seeder создает `market-settings.update`, но `MarketSettings::canAccess()` проверяет `market-settings.edit`, а право редактирования внутри страницы дополнительно смотрит `markets.update`.

Итог: permission-only сценарий для настроек рынка трудно понять и легко настроить неправильно.

Файлы:

- `database/seeders/RolesAndPermissionsSeeder.php:20`
- `app/Filament/Pages/MarketSettings.php:147`
- `app/Filament/Pages/MarketSettings.php:1052`

Рекомендация: привести к одной схеме:

- просмотр: `market-settings.view`
- редактирование: `market-settings.update`

`markets.update` оставить только для ресурса рынков или убрать из настроек рынка, если он там не нужен.

### P2. Типы мест редактируются слишком широко

`MarketSpaceTypeResource` сейчас повторяет общий шаблон `super-admin || market_id` для просмотра, создания, редактирования и удаления.

Типы мест стали пользовательским справочником рынка, но удаление/создание типа должно быть управленческим действием, а не доступом любой внутренней роли.

Файл: `app/Filament/Resources/MarketSpaceTypeResource.php:278`

Рекомендация: управление типами мест дать `super-admin`, `market-admin`, возможно `market-manager`; просмотр можно оставить шире, если это нужно в UI.

### P2. Карта рынка использует отдельные правила, но часть read-доступа шире ожидаемого

Просмотр карты и PDF карты разрешается `market-admin`, `market-maintenance`, permissions `markets.view`, `markets.update`, `markets.viewAny`, а также `contracts.update`.

Редактирование фигур ограничено `super-admin`, `market-admin`, `markets.update`. Привязка договоров ограничена `super-admin`, `market-admin`, `contracts.update`.

Это в целом безопаснее, чем ресурсы с простым `market_id`, но правило "кто может смотреть карту и финансовый статус на карте" нужно явно описать продуктово.

Файлы:

- `routes/web.php:530`
- `routes/web.php:918`
- `routes/web.php:945`
- `routes/web.php:4414`
- `routes/web.php:4731`

Рекомендация: зафиксировать матрицу:

- просмотр карты;
- редактирование разметки;
- привязка договоров;
- просмотр финансового статуса на popup.

### P3. Нет единой матрицы ролей в коде

Сейчас проверки разбросаны по Filament resources, pages, route closures и services. Из-за этого новые ресурсы легко делают по старому шаблону `super-admin || market_id`.

Рекомендация: добавить единый класс, например `App\Support\AdminCapabilities`, и использовать его в новых и постепенно в старых местах:

- `canEnterAdminPanel(User $user)`
- `canManageMarketDirectory(User $user, ?int $marketId)`
- `canViewFinance(User $user, ?int $marketId)`
- `canManageFinance(User $user, ?int $marketId)`
- `canManageMapShapes(User $user, ?int $marketId)`
- `canBindContracts(User $user, ?int $marketId)`
- `canManageStaff(User $user, ?int $marketId)`

## Хорошие места

- `PermissionResource` и `RoleResource` доступны только `super-admin`.
- `StaffResource` и `StaffInvitationResource` лучше ограничены: есть permissions `staff.*`, same-market scope, запрет редактирования/удаления super-admin и merchant-пользователей.
- `TenantContractResource` уже ограничивает доступ `super-admin`, `market-admin`, `market-manager`, а `market-operator` тестами проверяется как forbidden.
- `OpsDiagnostics` показывает страницу `super-admin` и `market-operator`, но опасные действия закрыты через `ensureSuperAdmin()`.
- Кабинет арендатора scoped по `tenant_id` и allowed spaces.
- API 1C не принимает данные без активного integration token.

## Предлагаемый порядок исправлений

1. Ввести `AdminCapabilities` без изменения поведения, покрыть unit-тестами матрицу ролей.
2. Перевести финансовые страницы 1C на `canViewFinance`.
3. Перевести `MarketSpaceResource`, `TenantResource`, `MarketSpaceTypeResource` на отдельные capabilities управления.
4. Исправить `market-settings.edit`/`market-settings.update`.
5. Добавить feature-тесты на negative cases: `market-guard`, `market-maintenance`, `market-marketing`, `staff` не могут редактировать арендаторов, места, типы мест и финансы.
6. Отдельно описать продуктовую матрицу для карты: кто видит карту, кто видит долг, кто меняет разметку, кто привязывает договоры.

## Минимальный набор тестов для следующего PR

- `market-admin` может управлять местами, арендаторами и типами мест своего рынка.
- `market-manager` - подтвердить ожидаемое поведение продуктово и закрепить тестом.
- `market-guard`, `market-security`, `market-maintenance`, `market-marketing`, `staff` не могут создавать/редактировать места и арендаторов.
- `market-accountant` и `market-finance` могут видеть финансы 1C, но не могут редактировать места и арендаторов, если это не задано отдельно.
- `market-operator` может видеть журнал интеграций, но не получает широкие права на справочники.
- permission `market-settings.update` реально разрешает редактирование настроек рынка.

