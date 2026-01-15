#!/usr/bin/env python3
"""
Test RAG search quality with sample queries.
Usage: python test_search.py --kb=1 --query="test query"
"""

import argparse
import json
import os
import sys
from typing import Optional

try:
    import psycopg2
    from psycopg2.extras import RealDictCursor
except ImportError:
    print("Please install psycopg2: pip install psycopg2-binary")
    sys.exit(1)


def get_connection():
    """Get database connection from environment."""
    db_url = os.environ.get('DATABASE_URL')
    if not db_url:
        print("DATABASE_URL environment variable not set")
        sys.exit(1)
    return psycopg2.connect(db_url, cursor_factory=RealDictCursor)


def search(conn, kb_id: int, query: str, limit: int = 10, threshold: float = 0.7):
    """Perform vector search."""
    # For testing, we use a mock embedding
    # In production, you'd call the embedding API

    with conn.cursor() as cur:
        # Get sample embedding dimension
        cur.execute("""
            SELECT vector_dims(embedding) as dim
            FROM knowledge_chunks
            WHERE knowledge_base_id = %s
            LIMIT 1
        """, (kb_id,))

        result = cur.fetchone()
        if not result:
            print(f"No chunks found for knowledge base {kb_id}")
            return []

        dim = result['dim']

        # Simple keyword search fallback (for testing without embeddings)
        cur.execute("""
            SELECT id, content,
                   ts_rank(to_tsvector('simple', content),
                          plainto_tsquery('simple', %s)) as score
            FROM knowledge_chunks
            WHERE knowledge_base_id = %s
              AND to_tsvector('simple', content) @@ plainto_tsquery('simple', %s)
            ORDER BY score DESC
            LIMIT %s
        """, (query, kb_id, query, limit))

        results = cur.fetchall()

        print(f"\n🔍 Search Results for: \"{query}\"")
        print("=" * 60)
        print(f"Knowledge Base: {kb_id}")
        print(f"Threshold: {threshold}")
        print(f"Results found: {len(results)}")
        print("")

        for i, row in enumerate(results, 1):
            print(f"{i}. [Score: {row['score']:.4f}]")
            print(f"   ID: {row['id']}")
            content = row['content'][:200] + "..." if len(row['content']) > 200 else row['content']
            print(f"   Content: {content}")
            print("")

        return results


def analyze_kb(conn, kb_id: int):
    """Analyze knowledge base statistics."""
    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                COUNT(*) as chunk_count,
                AVG(LENGTH(content)) as avg_length,
                MIN(LENGTH(content)) as min_length,
                MAX(LENGTH(content)) as max_length
            FROM knowledge_chunks
            WHERE knowledge_base_id = %s
        """, (kb_id,))

        stats = cur.fetchone()

        print(f"\n📊 Knowledge Base {kb_id} Statistics")
        print("=" * 40)
        print(f"Total chunks: {stats['chunk_count']}")
        print(f"Avg content length: {stats['avg_length']:.0f} chars")
        print(f"Min content length: {stats['min_length']} chars")
        print(f"Max content length: {stats['max_length']} chars")


def main():
    parser = argparse.ArgumentParser(description='Test RAG search')
    parser.add_argument('--kb', type=int, required=True, help='Knowledge base ID')
    parser.add_argument('--query', type=str, help='Search query')
    parser.add_argument('--limit', type=int, default=10, help='Result limit')
    parser.add_argument('--threshold', type=float, default=0.7, help='Similarity threshold')
    parser.add_argument('--analyze', action='store_true', help='Show KB statistics')

    args = parser.parse_args()

    conn = get_connection()

    try:
        if args.analyze:
            analyze_kb(conn, args.kb)

        if args.query:
            search(conn, args.kb, args.query, args.limit, args.threshold)
        elif not args.analyze:
            print("Please provide --query or --analyze")

    finally:
        conn.close()


if __name__ == "__main__":
    main()
