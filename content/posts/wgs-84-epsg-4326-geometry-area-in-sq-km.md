---
date: 2021-07-17
layout: post
title: Getting area of WGS-84 geometries in SqKm
categories: ["snippets"]
tags: [quick-hint, postgis, postgresql, gis]
---

Getting area of geometries in [WGS-84/EPSG:4326](https://spatialreference.org/ref/epsg/wgs-84/) in square kilometers:

```sql
SELECT
  ST_Area(geometry, false) / 10^6 sq_km
FROM
  my_table
```
