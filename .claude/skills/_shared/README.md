# Shared Components

Universal templates and scripts for all skills.

## Contents

- `_template.md` - Master template for creating rule files
- `scripts/generate_agents.py` - Universal AGENTS.md generator

## Usage

### Generate AGENTS.md for a specific skill

```bash
# From anywhere
python3 .claude/skills/_shared/scripts/generate_agents.py --skill backend-dev

# From skill directory
cd .claude/skills/backend-dev
python3 ../_shared/scripts/generate_agents.py
```

### Generate all skills

```bash
python3 .claude/skills/_shared/scripts/generate_agents.py --all
```

## Creating New Rules

1. Copy `_template.md` to the skill's `rules/` directory
2. Rename to `{category}-{number}-{name}.md`
3. Fill in the YAML frontmatter
4. Add content sections
5. Run generator to update AGENTS.md

## Skill Setup

Each skill needs:

```
skill-name/
├── rules/
│   ├── _template.md     # Copy from _shared
│   ├── _sections.md     # Decision trees
│   └── *.md             # Rule files
├── scripts/
│   └── generate_agents.py  # Symlink or copy from _shared
├── metadata.json        # Category definitions
├── SKILL.md             # Main documentation (slimmed)
└── AGENTS.md            # Auto-generated
```

## metadata.json Schema

```json
{
  "name": "skill-name",
  "version": "2.0.0",
  "description": "Skill description",
  "ruleCount": 42,
  "categories": [
    {
      "prefix": "category",
      "name": "Category Name",
      "count": 10,
      "description": "What this category covers"
    }
  ],
  "impactLevels": {
    "CRITICAL": "Description",
    "HIGH": "Description",
    "MEDIUM": "Description",
    "LOW": "Description"
  },
  "techStack": {
    "key": "version"
  }
}
```
