# 📋 Production Diagnostic Checklist — Этап 3.2

**Дата:** 2026-03-09  
**Ветка:** `feat/debt-monitoring-stage-2`  
**Цель:** Проверить фактическое состояние интеграции договоров 1С и долгов на prod

---

## ⚠️ ВАЖНО

- **НЕ ДЕПЛОИТЬ** на prod до выполнения проверок
- Запустить команды ниже на **prod-сервере**
- Сохранить выводы всех команд
- Прислать результаты для анализа

---

## 1. Проверить, есть ли вообще договоры в `tenant_contracts`

```bash
php artisan tinker --execute="
dump(DB::table('tenant_contracts')->count());
dump(DB::table('tenant_contracts')->whereNotNull('external_id')->count());
dump(DB::table('tenant_contracts')->whereNotNull('market_space_id')->count());
"
```

**Ожидаемый вывод:**
```
#1: общее количество записей
#2: количество с external_id (договоры из 1С)
#3: количество с market_space_id (привязанные к местам)
```

---

## 2. Проверить, есть ли долги в `contract_debts`

```bash
php artisan tinker --execute="
dump(DB::table('contract_debts')->count());
dump(DB::table('contract_debts')->distinct()->count('contract_external_id'));
dump(DB::table('contract_debts')->distinct()->count('tenant_external_id'));
"
```

**Ожидаемый вывод:**
```
#1: общее количество записей о долгах
#2: количество уникальных договоров (contract_external_id)
#3: количество уникальных арендаторов (tenant_external_id)
```

---

## 3. Проверить, есть ли связка долг → договор

```bash
php artisan tinker --execute="
dump(
    DB::table('contract_debts as cd')
        ->join('tenant_contracts as tc', 'tc.external_id', '=', 'cd.contract_external_id')
        ->count()
);
"
```

**Ожидаемый вывод:**
```
#1: количество долгов, для которых найден договор в tenant_contracts
```

---

## 4. Проверить, сколько договоров реально привязаны к местам

```bash
php artisan tinker --execute="
dump(
    DB::table('tenant_contracts')
        ->whereNotNull('external_id')
        ->whereNotNull('market_space_id')
        ->count()
);
dump(
    DB::table('tenant_contracts')
        ->whereNotNull('external_id')
        ->whereNull('market_space_id')
        ->count()
);
"
```

**Ожидаемый вывод:**
```
#1: договоры из 1С с market_space_id (привязанные)
#2: договоры из 1С БЕЗ market_space_id (непривязанные)
```

---

## 5. Проверить, сколько долгов можно разложить по местам

```bash
php artisan tinker --execute="
dump(
    DB::table('contract_debts as cd')
        ->join('tenant_contracts as tc', 'tc.external_id', '=', 'cd.contract_external_id')
        ->whereNotNull('tc.market_space_id')
        ->count()
);
"
```

**Ожидаемый вывод:**
```
#1: количество долгов, которые можно привязать к местам через договор
```

---

## 6. Посмотреть несколько живых примеров

```bash
php artisan tinker --execute="
dump(
    DB::table('contract_debts as cd')
        ->leftJoin('tenant_contracts as tc', 'tc.external_id', '=', 'cd.contract_external_id')
        ->select(
            'cd.contract_external_id',
            'cd.tenant_external_id',
            'cd.period',
            'cd.debt_amount',
            'tc.id as tenant_contract_id',
            'tc.market_space_id'
        )
        ->orderByDesc('cd.id')
        ->limit(20)
        ->get()
        ->toArray()
);
"
```

**Ожидаемый вывод:**
```
Массив из 20 последних записей contract_debts с данными о привязке к договору
```

---

## 📊 Интерпретация результатов

### Сценарий A

| Показатель | Значение |
|------------|----------|
| `contract_debts` count | > 0 |
| `tenant_contracts` count | 0 или очень мало |

**Вывод:**  
Договоры из 1С **сейчас не загружаются**. Долги приходят, но без справочника договоров.

**Действия:**
1. Проверить, работает ли `/api/1c/contracts` на prod
2. Проверить логи 1C: отправляются ли договоры
3. Проверить `IntegrationExchange` на ошибки по contracts

---

### Сценарий B

| Показатель | Значение |
|------------|----------|
| `tenant_contracts` with `external_id` | > 0 |
| `tenant_contracts` with `market_space_id` | 0 или очень мало (< 10%) |

**Вывод:**  
Договоры загружаются, но **не привязываются к местам**.

**Возможные причины:**
1. 1С не передаёт `market_space_code`
2. Ключи не матчатся с `market_spaces.code/number`
3. Коллизии в справочнике мест

**Действия:**
1. Посмотреть примеры из проверки #6
2. Проверить `market_space_code` в payload 1С
3. Запустить диагностику из Этапа 3.2 (linkage_stats)

---

### Сценарий C

| Показатель | Значение |
|------------|----------|
| `tenant_contracts` with `market_space_id` | > 50% от total |
| `contract_debts` join `tenant_contracts` | > 50% от total |

**Вывод:**  
Интеграция **частично рабочая**. Нужно добивать качество сопоставления.

**Действия:**
1. Посмотреть % непривязанных договоров (проверка #4)
2. Посмотреть diagnostics из API response
3. Применить фикс Этапа 3.2 для улучшения привязки

---

## 🎯 Что нужно понять по результатам

1. **Загружаются ли вообще договоры из 1С?**
   - Если нет → срочно чинить `/api/1c/contracts`

2. **Привязываются ли договоры к местам?**
   - Если нет → смотреть `market_space_code` в 1С

3. **Можно ли разложить долги по местам?**
   - Если нет → проблема в связке `contract_external_id`

4. **Почему места уходят в gray на карте?**
   - Нет договора в БД
   - Договор есть, но `market_space_id = null`
   - Долг есть, но `contract_external_id` не матчится

---

## 📝 Шаблон для отчёта

```
## Проверка на prod (2026-03-09)

### 1. tenant_contracts
- total: XXX
- with external_id: XXX
- with market_space_id: XXX

### 2. contract_debts
- total: XXX
- unique contracts: XXX
- unique tenants: XXX

### 3. Связка долг → договор
- joined: XXX

### 4. Договоры привязаны к местам
- with market_space_id: XXX
- without market_space_id: XXX

### 5. Долги с привязкой к местам
- with market_space_id: XXX

### 6. Примеры (последние 20)
[вставить вывод]

### Сценарий
[A / B / C]

### Вывод
[текст]
```

---

## ✅ Следующие шаги после проверки

1. **Если Сценарий A** → срочно делать выгрузку договоров из 1С
2. **Если Сценарий B** → чинить привязку `market_space_code`
3. **Если Сценарий C** → применять Этап 3.2, мониторить linkage_stats

---

**Контакты:** [ответственный за интеграцию 1С]  
**Канал связи:** [Telegram/Slack чат]
