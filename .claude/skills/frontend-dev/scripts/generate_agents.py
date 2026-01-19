#!/usr/bin/env python3
"""
Generate AGENTS.md from rule files in the rules/ directory.

This script:
1. Reads all rule markdown files
2. Parses YAML frontmatter
3. Groups rules by category
4. Sorts by impact level (CRITICAL > HIGH > MEDIUM > LOW)
5. Generates a compiled AGENTS.md file

Usage:
    python3 generate_agents.py

Output:
    ../AGENTS.md
"""

import os
import re
from pathlib import Path
from datetime import datetime
from typing import Dict, List, Any

# Impact level priority (lower = higher priority)
IMPACT_PRIORITY = {
    'CRITICAL': 0,
    'HIGH': 1,
    'MEDIUM': 2,
    'LOW': 3,
}

# Category display order
CATEGORY_ORDER = [
    'gotcha',
    'react',
    'query',
    'state',
    'perf',
    'a11y',
    'style',
    'ts',
]

CATEGORY_NAMES = {
    'gotcha': 'Gotchas (Common Mistakes)',
    'react': 'React',
    'query': 'React Query',
    'state': 'State Management',
    'perf': 'Performance',
    'a11y': 'Accessibility',
    'style': 'Styling',
    'ts': 'TypeScript',
}


def parse_frontmatter(content: str) -> tuple[Dict[str, Any], str]:
    """Extract YAML frontmatter and body from markdown content."""
    frontmatter = {}
    body = content

    if content.startswith('---'):
        parts = content.split('---', 2)
        if len(parts) >= 3:
            yaml_content = parts[1].strip()
            body = parts[2].strip()

            # Simple YAML parser for our use case
            for line in yaml_content.split('\n'):
                if ':' in line:
                    key, value = line.split(':', 1)
                    key = key.strip()
                    value = value.strip()

                    # Handle arrays [a, b, c]
                    if value.startswith('[') and value.endswith(']'):
                        value = [v.strip() for v in value[1:-1].split(',')]
                    # Handle quoted strings
                    elif value.startswith('"') and value.endswith('"'):
                        value = value[1:-1]

                    frontmatter[key] = value

    return frontmatter, body


def get_summary(body: str) -> str:
    """Extract first paragraph after '## Why This Matters' as summary."""
    match = re.search(r'## Why This Matters\s*\n+(.+?)(?=\n\n|\n##|$)', body, re.DOTALL)
    if match:
        summary = match.group(1).strip()
        # Take first sentence or first 150 chars
        if '.' in summary:
            summary = summary.split('.')[0] + '.'
        if len(summary) > 150:
            summary = summary[:147] + '...'
        return summary
    return ''


def read_rules(rules_dir: Path) -> List[Dict[str, Any]]:
    """Read all rule files and parse their content."""
    rules = []

    for filepath in rules_dir.glob('*.md'):
        # Skip template and sections
        if filepath.name.startswith('_'):
            continue

        content = filepath.read_text()
        frontmatter, body = parse_frontmatter(content)

        if not frontmatter.get('id'):
            print(f"Warning: {filepath.name} missing 'id' in frontmatter")
            continue

        rules.append({
            'id': frontmatter.get('id', ''),
            'title': frontmatter.get('title', ''),
            'impact': frontmatter.get('impact', 'MEDIUM'),
            'impactDescription': frontmatter.get('impactDescription', ''),
            'category': frontmatter.get('category', 'other'),
            'tags': frontmatter.get('tags', []),
            'relatedRules': frontmatter.get('relatedRules', []),
            'filename': filepath.name,
            'summary': get_summary(body),
        })

    return rules


def group_by_category(rules: List[Dict[str, Any]]) -> Dict[str, List[Dict[str, Any]]]:
    """Group rules by category and sort by impact."""
    grouped = {}

    for rule in rules:
        category = rule['category']
        if category not in grouped:
            grouped[category] = []
        grouped[category].append(rule)

    # Sort each category by impact priority
    for category in grouped:
        grouped[category].sort(
            key=lambda r: (IMPACT_PRIORITY.get(r['impact'], 99), r['id'])
        )

    return grouped


def generate_markdown(grouped_rules: Dict[str, List[Dict[str, Any]]]) -> str:
    """Generate the AGENTS.md content."""
    lines = []

    # Header
    lines.append('# Frontend Development Rules Reference')
    lines.append('')
    lines.append('> Auto-generated from rule files. Do not edit directly.')
    lines.append(f'> Generated: {datetime.now().strftime("%Y-%m-%d %H:%M")}')
    lines.append('')

    # Table of Contents
    lines.append('## Table of Contents')
    lines.append('')

    total_rules = sum(len(rules) for rules in grouped_rules.values())
    lines.append(f'**Total Rules: {total_rules}**')
    lines.append('')

    for category in CATEGORY_ORDER:
        if category in grouped_rules:
            category_name = CATEGORY_NAMES.get(category, category.title())
            count = len(grouped_rules[category])
            critical = sum(1 for r in grouped_rules[category] if r['impact'] == 'CRITICAL')
            high = sum(1 for r in grouped_rules[category] if r['impact'] == 'HIGH')

            badge = ''
            if critical > 0:
                badge = f' ({critical} CRITICAL)'
            elif high > 0:
                badge = f' ({high} HIGH)'

            lines.append(f'- [{category_name}](#{category}) - {count} rules{badge}')

    lines.append('')

    # Impact Legend
    lines.append('## Impact Levels')
    lines.append('')
    lines.append('| Level | Description |')
    lines.append('|-------|-------------|')
    lines.append('| **CRITICAL** | Runtime failures, data loss, security issues |')
    lines.append('| **HIGH** | UX degradation, performance issues |')
    lines.append('| **MEDIUM** | Code quality, maintainability |')
    lines.append('| **LOW** | Nice-to-have, minor improvements |')
    lines.append('')

    # Rules by Category
    for category in CATEGORY_ORDER:
        if category not in grouped_rules:
            continue

        category_name = CATEGORY_NAMES.get(category, category.title())
        rules = grouped_rules[category]

        lines.append(f'## {category_name}')
        lines.append(f'<a name="{category}"></a>')
        lines.append('')

        # Summary table
        lines.append('| Rule | Impact | Title |')
        lines.append('|------|--------|-------|')

        for rule in rules:
            impact_badge = rule['impact']
            if impact_badge == 'CRITICAL':
                impact_badge = '**CRITICAL**'
            elif impact_badge == 'HIGH':
                impact_badge = '**HIGH**'

            lines.append(
                f"| [{rule['id']}](rules/{rule['filename']}) | {impact_badge} | {rule['title']} |"
            )

        lines.append('')

        # Brief descriptions
        for rule in rules:
            if rule['summary']:
                lines.append(f"**{rule['id']}**: {rule['summary']}")
                lines.append('')

    # Quick Reference
    lines.append('## Quick Reference by Tag')
    lines.append('')

    # Collect all tags
    all_tags = {}
    for rules in grouped_rules.values():
        for rule in rules:
            tags = rule.get('tags', [])
            if isinstance(tags, str):
                tags = [tags]
            for tag in tags:
                if tag not in all_tags:
                    all_tags[tag] = []
                all_tags[tag].append(rule['id'])

    # Sort tags and output
    for tag in sorted(all_tags.keys()):
        rule_ids = all_tags[tag]
        lines.append(f"- **{tag}**: {', '.join(rule_ids)}")

    lines.append('')

    return '\n'.join(lines)


def main():
    script_dir = Path(__file__).parent
    rules_dir = script_dir.parent / 'rules'
    output_file = script_dir.parent / 'AGENTS.md'

    if not rules_dir.exists():
        print(f"Error: Rules directory not found at {rules_dir}")
        return 1

    print(f"Reading rules from {rules_dir}")
    rules = read_rules(rules_dir)
    print(f"Found {len(rules)} rules")

    grouped = group_by_category(rules)
    markdown = generate_markdown(grouped)

    output_file.write_text(markdown)
    print(f"Generated {output_file}")

    # Print summary
    print("\nSummary:")
    for category in CATEGORY_ORDER:
        if category in grouped:
            print(f"  {CATEGORY_NAMES.get(category, category)}: {len(grouped[category])} rules")

    return 0


if __name__ == '__main__':
    exit(main())
