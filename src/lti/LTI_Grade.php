<?php
namespace IMSGlobal\LTI;

class LTI_Grade {
    private int|float|null $score_given = null;
    private int|float|null $score_maximum = null;
    private ?string $comment = null;
    private ?string $activity_progress = null;
    private ?string $grading_progress = null;
    private ?string $timestamp = null;
    private ?string $user_id = null;
    private ?LTI_Grade_Submission_Review $submission_review = null;

    /**
     * Static function to allow for method chaining without having to assign to a variable first.
     */
    public static function new(): self {
        return new LTI_Grade();
    }

    public function get_score_given(): int|float|null {
        return $this->score_given;
    }

    public function set_score_given(int|float $value): self {
        $this->score_given = $value;
        return $this;
    }

    public function get_score_maximum(): int|float|null {
        return $this->score_maximum;
    }

    public function set_score_maximum(int|float $value): self {
        $this->score_maximum = $value;
        return $this;
    }

    public function get_comment(): ?string {
        return $this->comment;
    }

    public function set_comment(?string $comment): self {
        $this->comment = $comment;
        return $this;
    }

    public function get_activity_progress(): ?string {
        return $this->activity_progress;
    }

    public function set_activity_progress(string $value): self {
        $this->activity_progress = $value;
        return $this;
    }

    public function get_grading_progress(): ?string {
        return $this->grading_progress;
    }

    public function set_grading_progress(string $value): self {
        $this->grading_progress = $value;
        return $this;
    }

    public function get_timestamp(): ?string {
        return $this->timestamp;
    }

    public function set_timestamp(string $value): self {
        $this->timestamp = $value;
        return $this;
    }

    public function get_user_id(): ?string {
        return $this->user_id;
    }

    public function set_user_id(string $value): self {
        $this->user_id = $value;
        return $this;
    }

    public function get_submission_review(): ?LTI_Grade_Submission_Review {
        return $this->submission_review;
    }

    public function set_submission_review(LTI_Grade_Submission_Review $value): self {
        $this->submission_review = $value;
        return $this;
    }

    public function __toString(): string {
        return json_encode(array_filter([
            "scoreGiven" => 0 + $this->score_given,
            "scoreMaximum" => 0 + $this->score_maximum,
            "comment" => $this->comment,
            "activityProgress" => $this->activity_progress,
            "gradingProgress" => $this->grading_progress,
            "timestamp" => $this->timestamp,
            "userId" => $this->user_id,
            "submissionReview" => $this->submission_review,
        ]));
    }
}
?>
