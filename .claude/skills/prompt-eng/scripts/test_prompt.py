#!/usr/bin/env python3
"""
Test prompt effectiveness with sample inputs.
Usage: python test_prompt.py --prompt="prompt.txt" --cases="cases.json"
"""

import argparse
import json
import os
import sys
from typing import Optional

# Try to import OpenAI client
try:
    from openai import OpenAI
except ImportError:
    OpenAI = None


def load_prompt(filepath: str) -> str:
    """Load prompt from file."""
    with open(filepath, 'r', encoding='utf-8') as f:
        return f.read()


def load_test_cases(filepath: str) -> list:
    """Load test cases from JSON file."""
    with open(filepath, 'r', encoding='utf-8') as f:
        return json.load(f)


def evaluate_response(response: str, expected: dict) -> dict:
    """Evaluate response against expected criteria."""
    results = {
        'passed': True,
        'checks': []
    }

    # Check required patterns
    if 'must_contain' in expected:
        for pattern in expected['must_contain']:
            found = pattern.lower() in response.lower()
            results['checks'].append({
                'type': 'must_contain',
                'pattern': pattern,
                'passed': found
            })
            if not found:
                results['passed'] = False

    # Check forbidden patterns
    if 'must_not_contain' in expected:
        for pattern in expected['must_not_contain']:
            found = pattern.lower() in response.lower()
            results['checks'].append({
                'type': 'must_not_contain',
                'pattern': pattern,
                'passed': not found
            })
            if found:
                results['passed'] = False

    # Check length
    if 'max_length' in expected:
        within_limit = len(response) <= expected['max_length']
        results['checks'].append({
            'type': 'max_length',
            'limit': expected['max_length'],
            'actual': len(response),
            'passed': within_limit
        })
        if not within_limit:
            results['passed'] = False

    return results


def test_with_llm(prompt: str, test_cases: list, model: str = "gpt-4o-mini"):
    """Run tests using LLM."""
    if OpenAI is None:
        print("OpenAI package not installed. Install with: pip install openai")
        return

    api_key = os.environ.get('OPENAI_API_KEY') or os.environ.get('OPENROUTER_API_KEY')
    if not api_key:
        print("Please set OPENAI_API_KEY or OPENROUTER_API_KEY environment variable")
        return

    # Determine base URL
    base_url = None
    if os.environ.get('OPENROUTER_API_KEY'):
        base_url = "https://openrouter.ai/api/v1"
        api_key = os.environ.get('OPENROUTER_API_KEY')

    client = OpenAI(api_key=api_key, base_url=base_url)

    print(f"\n🧪 Testing Prompt with {len(test_cases)} cases")
    print("=" * 50)

    passed = 0
    failed = 0

    for i, case in enumerate(test_cases, 1):
        print(f"\nTest {i}: {case.get('name', 'Unnamed')}")
        print(f"Input: {case['input'][:50]}...")

        try:
            response = client.chat.completions.create(
                model=model,
                messages=[
                    {"role": "system", "content": prompt},
                    {"role": "user", "content": case['input']}
                ],
                max_tokens=500
            )

            output = response.choices[0].message.content
            print(f"Output: {output[:100]}...")

            if 'expected' in case:
                result = evaluate_response(output, case['expected'])
                if result['passed']:
                    print("✅ PASSED")
                    passed += 1
                else:
                    print("❌ FAILED")
                    for check in result['checks']:
                        if not check['passed']:
                            print(f"   Failed: {check['type']} - {check.get('pattern', '')}")
                    failed += 1
            else:
                print("⚠️  No expected criteria defined")

        except Exception as e:
            print(f"❌ Error: {e}")
            failed += 1

    print("\n" + "=" * 50)
    print(f"Results: {passed} passed, {failed} failed")


def test_offline(prompt: str, test_cases: list):
    """Show test cases without calling LLM."""
    print("\n📋 Test Cases (Offline Mode)")
    print("=" * 50)

    for i, case in enumerate(test_cases, 1):
        print(f"\n{i}. {case.get('name', 'Unnamed')}")
        print(f"   Input: {case['input']}")
        if 'expected' in case:
            print(f"   Expected: {case['expected']}")

    print("\n💡 To run with LLM, set OPENAI_API_KEY or OPENROUTER_API_KEY")


def main():
    parser = argparse.ArgumentParser(description='Test prompt effectiveness')
    parser.add_argument('--prompt', type=str, required=True, help='Prompt file path')
    parser.add_argument('--cases', type=str, required=True, help='Test cases JSON file')
    parser.add_argument('--model', type=str, default='gpt-4o-mini', help='Model to use')
    parser.add_argument('--offline', action='store_true', help='Show cases without LLM call')

    args = parser.parse_args()

    prompt = load_prompt(args.prompt)
    test_cases = load_test_cases(args.cases)

    print(f"Loaded prompt: {len(prompt)} characters")
    print(f"Loaded {len(test_cases)} test cases")

    if args.offline:
        test_offline(prompt, test_cases)
    else:
        test_with_llm(prompt, test_cases, args.model)


if __name__ == "__main__":
    main()
