---
date: 2023-08-21
layout: post
title: Normalizing heterogeneous decimal Ion data in Athena
categories: ["snippets"]
tags: [athena, aws, sql]
---

Recently, we exported data from a DynamoDB table to S3 in [AWS Ion format](https://amazon-ion.github.io/ion-docs/docs/spec.html).  However, due to the fact that the DynamoDB table had varied formats for some numeric properties, the export serialized these numeric data columns in a few different formats: as a decimal (`1234.`), as an [Ion decimal type](https://amazon-ion.github.io/ion-docs/docs/decimal.html) (`1234d0`), and as a string (`"1234"`).  However, we want to be able to treat these values as a `bigint` within our Athena queries.

Our solution was to create a view similar to the following that would convert any of those formats into a `bigint`:

```sql
SELECT
  CAST(
		-- Convert `size` from Ion Decimal string to bigint
		CAST(
			SUBSTRING(
				i.size,
				1,
				CASE
					STRPOS(i.size, 'd')
					WHEN 0 THEN LENGTH(i.size) ELSE STRPOS(i.size, 'd') - 1
				END
			) AS DECIMAL(32, 16)
		) * POWER(
			10,
			CASE
				STRPOS(i.size, 'd')
				WHEN 0 THEN 0 ELSE CAST(
					SUBSTRING(
						i.size,
						STRPOS(i.size, 'd') + 1,
						LENGTH(i.size)
					) as BIGINT
				)
			END
		) as BIGINT
	) size
FROM table i
```

This works by splitting the values the location of a `d` character within the value and multiplying the value to the left of the `d` character by `10` to the power of the value to the right of the `d` character.  Examples:

* `1234d1` -> `1234 * 10 ** 1` -> `12340`
* `12345` -> `12345 * 10 ** 0` -> `12345`
