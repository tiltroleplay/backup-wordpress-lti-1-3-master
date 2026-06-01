<?php
namespace IMSGlobal\LTI;

class LTI_Lineitem {
    private ?string $id = null;
    private int|float|null $score_maximum = null;
    private ?string $label = null;
    private ?string $resource_id = null;
    private ?string $tag = null;
    private ?string $start_date_time = null;
    private ?string $end_date_time = null;

    public function __construct(?array $lineitem = null) {
        if (empty($lineitem)) {
            return;
        }
        $this->id = $lineitem["id"] ?? null;
        $this->score_maximum = $lineitem["scoreMaximum"] ?? null;
        $this->label = $lineitem["label"] ?? null;
        $this->resource_id = $lineitem["resourceId"] ?? null;
        $this->tag = $lineitem["tag"] ?? null;
        $this->start_date_time = $lineitem["startDateTime"] ?? null;
        $this->end_date_time = $lineitem["endDateTime"] ?? null;
    }

    /**
     * Static function to allow for method chaining without having to assign to a variable first.
     */
    public static function new(): self {
        return new LTI_Lineitem();
    }

    public function get_id(): ?string {
        return $this->id;
    }

    public function set_id(string $value): self {
        $this->id = $value;
        return $this;
    }

    public function get_label(): ?string {
        return $this->label;
    }

    public function set_label(string $value): self {
        $this->label = $value;
        return $this;
    }

    public function get_score_maximum(): int|float|null {
        return $this->score_maximum;
    }

    public function set_score_maximum(int|float $value): self {
        $this->score_maximum = $value;
        return $this;
    }

    public function get_resource_id(): ?string {
        return $this->resource_id;
    }

    public function set_resource_id(string $value): self {
        $this->resource_id = $value;
        return $this;
    }

    public function get_tag(): ?string {
        return $this->tag;
    }

    public function set_tag(string $value): self {
        $this->tag = $value;
        return $this;
    }

    public function get_start_date_time(): ?string {
        return $this->start_date_time;
    }

    public function set_start_date_time(string $value): self {
        $this->start_date_time = $value;
        return $this;
    }

    public function get_end_date_time(): ?string {
        return $this->end_date_time;
    }

    public function set_end_date_time(string $value): self {
        $this->end_date_time = $value;
        return $this;
    }

    public function __toString(): string {
        return json_encode(array_filter([
            "id" => $this->id,
            "scoreMaximum" => $this->score_maximum,
            "label" => $this->label,
            "resourceId" => $this->resource_id,
            "tag" => $this->tag,
            "startDateTime" => $this->start_date_time,
            "endDateTime" => $this->end_date_time,
        ]));
    }
}
?>
