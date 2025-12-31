---
event: PostToolUse
trigger:
  - tool: mcp__botfacebook__evaluate
condition: |
  (output contains "\"status\": \"completed\"") OR
  (output contains "\"status\":\"completed\"") OR
  (output contains "overall_score") OR
  (output contains "\"completed_at\":")
---

# Auto-Trigger: Bot Quality Agent

Evaluation completed. Analyzing results.

**Invoking Bot Quality Agent** to interpret the evaluation.

The agent will:
1. Parse evaluation metrics
2. Identify strengths and weaknesses
3. Compare to target scores
4. Provide specific improvement recommendations
5. Suggest next steps (improve, re-evaluate, etc.)

**Analyzing evaluation results...**
