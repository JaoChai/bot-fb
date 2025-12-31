---
name: thai-nlp
description: Debug และปรับปรุง Thai language processing - ใช้เมื่อ semantic search ภาษาไทยไม่ทำงาน, threshold ไม่เหมาะสม, หรือต้องการ debug Thai tokenization
---

# Thai NLP Specialist

ใช้ skill นี้เมื่อต้องการ debug หรือปรับปรุง Thai language processing ใน BotFacebook

## Thai Similarity Threshold Guide

### ปัญหาหลักของ Thai Embeddings

1. **Tokenization ต่างจาก English**
   - Thai ไม่มี space ระหว่างคำ
   - Embedding models (text-embedding-3-small) ถูก train บน English มากกว่า

2. **Similarity Score ต่ำกว่า English**
   - English queries มักได้ 0.75-0.85
   - Thai queries มักได้ 0.55-0.70

### แนะนำ Threshold สำหรับ Thai

| Use Case | Threshold | Notes |
|----------|-----------|-------|
| General Thai | 0.50-0.55 | Default สำหรับ KB ภาษาไทย |
| Thai + English Mixed | 0.55-0.60 | เมื่อมีทั้งสองภาษาใน KB |
| Thai Technical Terms | 0.45-0.50 | ศัพท์เทคนิคที่ยาก |
| Pure English KB | 0.70-0.75 | Standard threshold |

### วิธีปรับ Threshold

**Per-Bot Setting:**
```
Bot Settings > Knowledge Base > Relevance Threshold
```

**Global Default (config/rag.php):**
```php
'threshold' => env('RAG_THRESHOLD', 0.50), // ลดจาก 0.70
```

---

## Thai Language Detection

### Current Implementation
```php
// RAGService.php line 893-905
protected function detectLanguage(string $message): string
{
    $thaiCharCount = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $message);
    $totalChars = mb_strlen($message);
    if ($totalChars > 0 && ($thaiCharCount / $totalChars) > 0.2) {
        return 'thai';
    }
    return 'english';
}
```

**Thai Unicode Range:** `\x{0E00}-\x{0E7F}` (Thai script block)

### Debug Language Detection
```php
// ใน tinker
$message = "สวัสดีครับ ต้องการสอบถามเรื่อง API";
$thaiChars = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $message);
$total = mb_strlen($message);
echo "Thai ratio: " . ($thaiChars / $total); // ควรได้ > 0.2
```

---

## Thai Abbreviation Expansion

### ปัญหา
- User พิมพ์ "มค." แต่ KB มี "มกราคม"
- Search ไม่เจอเพราะ embedding ต่างกัน

### วิธีแก้ (QueryEnhancementService)
- Enable query enhancement ใน Bot Settings
- System จะ expand abbreviations อัตโนมัติ

### Common Thai Abbreviations
ดู `data/abbreviations.csv` สำหรับรายการทั้งหมด

---

## Complexity Detection for Thai

### Thai Keywords ที่ trigger Chain-of-Thought

| Keyword | Type | Example |
|---------|------|---------|
| เปรียบเทียบ | comparison | "เปรียบเทียบ A กับ B" |
| วิเคราะห์ | analysis | "วิเคราะห์ข้อมูลนี้" |
| ทำไม | reasoning | "ทำไมถึงเป็นแบบนี้" |
| อธิบาย | explanation | "อธิบายขั้นตอน" |
| ข้อดีข้อเสีย | pros/cons | "ข้อดีข้อเสียของ..." |
| ทีละขั้นตอน | step-by-step | "อธิบายทีละขั้นตอน" |
| คำนวณ | calculation | "คำนวณราคา" |
| ถ้า/สมมติ | conditional | "ถ้าเป็นกรณีนี้" |

### Debug Complexity
```php
// ดู complexity score ใน logs
grep "complexity" storage/logs/laravel.log
```

---

## Embedding Quality Testing

### ทดสอบ Embedding ภาษาไทย

1. **สร้าง test queries:**
   ```
   Query 1: "ราคาสินค้า"
   Query 2: "ค่าใช้จ่าย"
   Query 3: "ราคาเท่าไหร่"
   ```

2. **ดู similarity กับ KB chunks:**
   - ถ้า synonyms ได้ score ต่ำมาก → embedding ไม่ดี
   - ถ้า exact match เท่านั้นที่ score สูง → ต้องใช้ hybrid search

3. **เปรียบเทียบกับ English:**
   ```
   Thai: "ราคาสินค้า" vs "ค่าใช้จ่าย" → ~0.65
   English: "product price" vs "cost" → ~0.75
   ```

---

## Troubleshooting

### Issue 1: Thai queries ไม่เจอผลลัพธ์

**วิธีตรวจสอบ:**
1. ดู actual similarity scores ใน logs
2. เทียบกับ threshold ที่ตั้งไว้
3. ลอง query เดียวกันเป็น English

**Solution:**
- ลด threshold เป็น 0.50
- Enable hybrid search (keyword + semantic)

### Issue 2: Mixed language confusion

**ปัญหา:** "สอบถามเรื่อง API key" ถูกตีความผิด

**Solution:**
- Use hybrid search
- Enable query enhancement
- ปรับ KB content ให้มี keywords ทั้งสองภาษา

### Issue 3: Thai abbreviations ไม่ถูก expand

**ปัญหา:** "กทม." ไม่ถูกแปลงเป็น "กรุงเทพมหานคร"

**Solution:**
1. เปิด Query Enhancement ใน Bot Settings
2. ตรวจสอบ abbreviation mapping ใน `data/abbreviations.csv`

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `backend/app/Services/RAGService.php` | detectLanguage(), detectComplexity() |
| `backend/app/Services/QueryEnhancementService.php` | Thai abbreviation expansion |
| `backend/app/Services/Evaluation/PersonaService.php` | Thai personas |
| `backend/config/rag.php` | Threshold configuration |
