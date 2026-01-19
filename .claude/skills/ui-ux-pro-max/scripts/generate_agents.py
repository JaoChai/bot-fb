#!/usr/bin/env python3
"""
Universal AGENTS.md generator for all skills.

This script:
1. Reads all rule markdown files from rules/ directory
2. Parses YAML frontmatter
3. Groups rules by category
4. Sorts by impact level (CRITICAL > HIGH > MEDIUM > LOW)
5. Generates a compiled AGENTS.md file

Usage:
    python3 generate_agents.py                    # Run from skill directory
    python3 generate_agents.py --skill backend-dev  # Run from _shared
    python3 generate_agents.py --all              # Generate all skills

Output:
    AGENTS.md in the skill directory
"""

import os
import re
import json
import argparse
from pathlib import Path
from datetime import datetime
from typing import Dict, List, Any, Optional

# Impact level priority (lower = higher priority)
IMPACT_PRIORITY = {
    'CRITICAL': 0,
    'HIGH': 1,
    'MEDIUM': 2,
    'LOW': 3,
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

    if not rules_dir.exists():
        return rules

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


def load_metadata(skill_dir: Path) -> Optional[Dict]:
    """Load metadata.json if exists."""
    metadata_file = skill_dir / 'metadata.json'
    if metadata_file.exists():
        return json.loads(metadata_file.read_text())
    return None


def group_by_category(rules: List[Dict[str, Any]], category_order: List[str]) -> Dict[str, List[Dict[str, Any]]]:
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


def generate_markdown(skill_name: str, grouped_rules: Dict[str, List[Dict[str, Any]]],
                      category_order: List[str], category_names: Dict[str, str]) -> str:
    """Generate the AGENTS.md content."""
    lines = []

    # Convert skill name to title
    title = skill_name.replace('-', ' ').title() + ' Rules Reference'

    # Header
    lines.append(f'# {title}')
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

    # Use provided order, then any remaining categories
    all_categories = list(category_order) + [c for c in grouped_rules.keys() if c not in category_order]

    for category in all_categories:
        if category in grouped_rules:
            category_name = category_names.get(category, category.title())
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
    lines.append('| **CRITICAL** | Prompt injection, security vulnerabilities |')
    lines.append('| **HIGH** | AI response quality, consistency |')
    lines.append('| **MEDIUM** | Optimization, performance |')
    lines.append('| **LOW** | Style, minor improvements |')
    lines.append('')

    # Rules by Category
    for category in all_categories:
        if category not in grouped_rules:
            continue

        category_name = category_names.get(category, category.title())
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


def process_skill(skill_dir: Path) -> int:
    """Process a single skill directory."""
    rules_dir = skill_dir / 'rules'
    output_file = skill_dir / 'AGENTS.md'

    # Load metadata for category configuration
    metadata = load_metadata(skill_dir)

    if metadata:
        categories = metadata.get('categories', [])
        category_order = [c['prefix'] for c in categories]
        category_names = {c['prefix']: c['name'] for c in categories}
    else:
        # Default fallback
        category_order = []
        category_names = {}

    skill_name = skill_dir.name

    print(f"Processing {skill_name}...")
    print(f"  Rules dir: {rules_dir}")

    if not rules_dir.exists():
        print(f"  Warning: Rules directory not found at {rules_dir}")
        return 1

    rules = read_rules(rules_dir)
    print(f"  Found {len(rules)} rules")

    if len(rules) == 0:
        print(f"  Warning: No rules found in {rules_dir}")
        return 1

    grouped = group_by_category(rules, category_order)
    markdown = generate_markdown(skill_name, grouped, category_order, category_names)

    output_file.write_text(markdown)
    print(f"  Generated {output_file}")

    # Print summary
    print("\n  Summary:")
    for category in category_order + [c for c in grouped.keys() if c not in category_order]:
        if category in grouped:
            print(f"    {category_names.get(category, category)}: {len(grouped[category])} rules")

    return 0


def find_skills_dir() -> Path:
    """Find the skills directory."""
    # Try current directory first
    cwd = Path.cwd()

    # If we're in a skill directory
    if (cwd / 'rules').exists():
        return cwd.parent

    # If we're in scripts directory
    if cwd.name == 'scripts':
        return cwd.parent.parent

    # If we're in _shared
    if cwd.name == '_shared':
        return cwd.parent

    # Look for .claude/skills
    for parent in [cwd] + list(cwd.parents):
        skills_dir = parent / '.claude' / 'skills'
        if skills_dir.exists():
            return skills_dir

    return cwd


def main():
    parser = argparse.ArgumentParser(description='Generate AGENTS.md for skills')
    parser.add_argument('--skill', '-s', help='Specific skill to generate')
    parser.add_argument('--all', '-a', action='store_true', help='Generate all skills')
    args = parser.parse_args()

    skills_dir = find_skills_dir()

    if args.all:
        # Generate for all skills that have rules/
        results = []
        for skill_dir in sorted(skills_dir.iterdir()):
            if skill_dir.is_dir() and not skill_dir.name.startswith('_'):
                rules_dir = skill_dir / 'rules'
                if rules_dir.exists():
                    result = process_skill(skill_dir)
                    results.append((skill_dir.name, result))

        print("\n" + "=" * 50)
        print("Summary:")
        for name, result in results:
            status = "OK" if result == 0 else "FAILED"
            print(f"  {name}: {status}")
        return 0 if all(r == 0 for _, r in results) else 1

    elif args.skill:
        skill_dir = skills_dir / args.skill
        if not skill_dir.exists():
            print(f"Error: Skill directory not found at {skill_dir}")
            return 1
        return process_skill(skill_dir)

    else:
        # Default: process current skill directory
        cwd = Path.cwd()
        if (cwd / 'rules').exists():
            return process_skill(cwd)
        elif cwd.name == 'scripts':
            return process_skill(cwd.parent)
        else:
            print("Error: Run from a skill directory or use --skill option")
            return 1


if __name__ == '__main__':
    exit(main())
