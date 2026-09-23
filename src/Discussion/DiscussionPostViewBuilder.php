<?php

declare(strict_types=1);

namespace App\Discussion;

use App\Entity\DiscussionPost;
use App\Repository\ProfileRepository;

final class DiscussionPostViewBuilder
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    /** @param list<DiscussionPost> $posts
     *  @return list<array{post: DiscussionPost, authorName: string, candidateId: ?int, html: string}>
     */
    public function build(array $posts, bool $linkCandidateAuthors): array
    {
        $userIds = array_values(array_unique(array_map(static fn (DiscussionPost $post): int => (int) $post->getAuthor()->getId(), $posts)));
        $details = $this->profiles->publicDetailsForUsers($userIds);
        $views = [];
        foreach ($posts as $post) {
            $author = $post->getAuthor();
            $id = (int) $author->getId();
            $candidate = in_array('ROLE_CANDIDATE', $author->getRoles(), true);
            $name = trim(($details[$id]['first name'] ?? '').' '.($details[$id]['last name'] ?? ''));
            $views[] = [
                'post' => $post,
                'authorName' => $name !== '' ? $name : ($candidate ? 'Candidate' : (in_array('ROLE_ADMIN', $author->getRoles(), true) ? 'Admin' : 'Recruiter')).' #'.$id,
                'candidateId' => $candidate && $linkCandidateAuthors ? $id : null,
                'html' => $this->markdown->render($post->getContent()),
            ];
        }
        return $views;
    }
}
