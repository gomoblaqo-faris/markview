# Table Examples

This page demonstrates table rendering in MarkView.

## Simple Table

| Name | Age | City |
|------|-----|------|
| Alice | 28 | New York |
| Bob | 35 | London |
| Charlie | 42 | Tokyo |

## Table with Alignment

| Item | Price | Quantity | Total |
|:-----|------:|---------:|:-----:|
| Apples | $2.50 | 10 | $25.00 |
| Bananas | $1.20 | 5 | $6.00 |
| Oranges | $3.00 | 8 | $24.00 |

**Alignment Key:**
- First column: Left-aligned (`:-----`)
- Second & third columns: Right-aligned (`-----:`)
- Fourth column: Center-aligned (`:-----:`)

## Complex Table

| Feature | MarkView | Other Tools |
|---------|----------|-------------|
| **File Browser** | ✅ Yes | ❌ No |
| **Link Navigation** | ✅ Yes | ✅ Yes |
| **Tailwind CSS** | ✅ Yes | ⚠️ Varies |
| **Single File** | ✅ Yes | ❌ No |
| **Tables** | ✅ Yes | ✅ Yes |

## Notes

- Tables must have a header row
- The separator row uses pipes and dashes: `|---|---|`
- Alignment is controlled by colons in the separator row
- Hover over rows to see the highlight effect
- Even rows have a subtle gray background

[Back to README](README.md)
