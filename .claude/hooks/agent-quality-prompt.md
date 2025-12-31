---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(ประเมิน|evaluate|evaluation|คุณภาพ bot|bot quality|quality score|คะแนน|score|test case|test bot|ทดสอบ bot|improve bot|ปรับปรุง bot|bot performance|metric|เมตริก|persona|benchmark|compare evaluation)"
---

# Auto-Trigger: Bot Quality Agent

Detected bot evaluation/quality keywords in user prompt.

**Invoking Bot Quality Agent** to help with evaluation and improvement.

The agent will:
1. List available bots and flows
2. Help configure evaluation parameters
3. Run or check evaluation progress
4. Analyze evaluation reports
5. Provide improvement recommendations
6. Compare before/after results

**Agent capabilities:**
- Create and run evaluations
- Analyze 5 key metrics (relevancy, faithfulness, role adherence, context precision, task completion)
- Compare multiple evaluations
- Suggest KB and prompt improvements
- Guide the improvement agent workflow

Please specify which bot you want to evaluate, or ask about existing evaluations.
