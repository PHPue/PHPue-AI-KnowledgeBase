<?php
    // backend/*.php is require_once'd on EVERY request (before routing and AJAX dispatch).
    // Keep files to definitions + idempotent bootstrap; never echo here.
    class CatalogueRepository {
        private static ?self $instance = null;

        private array $products = [
            ['id' => 'p1', 'name' => 'Oak Desk',     'category' => 'Furniture', 'price' => 24900],
            ['id' => 'p2', 'name' => 'Desk Lamp',    'category' => 'Lighting',  'price' => 3900],
            ['id' => 'p3', 'name' => 'Wall Shelf',   'category' => 'Furniture', 'price' => 5900],
            ['id' => 'p4', 'name' => 'Floor Lamp',   'category' => 'Lighting',  'price' => 8900],
        ];

        public static function getInstance(): self {
            if (self::$instance === null)
                self::$instance = new self();
            return self::$instance;
        }

        public function all(): array { return $this->products; }

        public function categories(): array {
            return array_values(array_unique(array_column($this->products, 'category')));
        }

        // Swap for PDO (prepared statements) in a real app, e.g. a DBLoader singleton.
        public function filter(?string $category): array {
            if (!$category) return $this->products;
            return array_values(array_filter($this->products, fn($p) => $p['category'] === $category));
        }
    }
?>
