<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Conversation;

/**
 * Helper for the topic-change feature.
 *
 * The decision of WHETHER an inbound message starts a new topic is made by the
 * query-builder LLM (ConversationalQueryBuilder returns `continues_topic`), which
 * judges the conversation by meaning in any language — far more reliably than a
 * keyword/stopword heuristic could. This class only derives a short, human-readable
 * subject for the conversation grid when a (new) topic is opened.
 */
class TopicResolver
{
    /**
     * A short, human-readable subject for the conversation grid.
     *
     * @param array<int, array<string, mixed>> $extractedItems
     */
    public function deriveSubject(string $resolvedQuery, array $extractedItems): string
    {
        $query = trim((string)preg_replace('/\s+/', ' ', $resolvedQuery));
        if ($query !== '') {
            return mb_substr($query, 0, 120);
        }
        foreach ($extractedItems as $item) {
            $name = trim((string)($item['name'] ?? ''));
            if ($name !== '') {
                return mb_substr($name, 0, 120);
            }
        }
        return '';
    }
}
