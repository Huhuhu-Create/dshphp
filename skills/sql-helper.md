---
name: SQL 编写与审查
description: 写查询、建表、迁移、索引或检查 SQL 性能与安全时使用
when: sql，数据库，查询，建表，改表，加字段，索引，join，group by，sqlite，mysql，postgres，慢查询
default: auto
---

## 规则

- 先声明目标方言（SQLite / MySQL / PostgreSQL）；未说明时用**三者兼容**的保守写法，并指出差异点（如 `AUTOINCREMENT`、`ON CONFLICT`、字符串拼接）。
- 一律使用参数绑定占位符（`:name` / `?`），**绝不拼接用户输入**；示例里也不要出现字符串插值。
- 建表给主键、`NOT NULL`、默认值、必要索引与时间字段类型；说明字符集与时间戳单位。
- 查询避免 `SELECT *`；大表分页用 `LIMIT/OFFSET` 或游标，并提醒深分页代价。
- `UPDATE` / `DELETE` 先给对应的 `SELECT` 预览影响行，并提醒先备份 / 放事务里。
- 迁移语句说明旧数据的默认值、是否锁表、以及回滚办法；加列时注意 ORM 或手写 INSERT 只覆盖固定列会静默丢字段。

## 输出

- 先给 SQL 代码块（标注方言），再给 2～3 条验证 SQL，最后一句说明风险。
- 审查类需求按「正确性 / 安全 / 性能 / 可维护性」四档列问题，每条给位置与改法。
