<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as SlimResponse;

$app = AppFactory::create();

// Allow Slim to parse JSON request bodies automatically
$app->addBodyParsingMiddleware();

// Secure error handling: only expose details when APP_DEBUG is explicitly enabled
$isDebug = in_array(strtolower((string) getenv('APP_DEBUG')), ['1', 'true', 'yes'], true);
$app->addErrorMiddleware($isDebug, $isDebug, $isDebug);

// ----------------------------
// A. Database Connection (PDO)
// ----------------------------
function getDbConnection(): PDO {
    global $host, $db, $user, $pass, $charset;

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    return new PDO($dsn, $user, $pass, $options);
}

// ----------------------------
// Helper: send JSON response
// ----------------------------
function jsonResponse(Response $response, $data, int $status = 200): Response {
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}

// ----------------------------
// Helper: generic 500 on unexpected DB/runtime errors
// ----------------------------
function serverErrorResponse(Response $response): Response {
    return jsonResponse($response, [
        'status'  => 'error',
        'message' => 'An unexpected server error occurred.'
    ], 500);
}

// ----------------------------
// Helper: sanitize free-text input (trim + strip control chars; keep Unicode)
// ----------------------------
function sanitizeString(string $value): string {
    $value = trim($value);
    // Remove null bytes and other C0/C1 control characters except common whitespace
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return trim($value);
}

// ----------------------------
// Helper: parse a positive integer ID from a route/query value
// ----------------------------
function parsePositiveIntId($value): ?int {
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    $str = trim((string) $value);
    if ($str === '' || !ctype_digit($str)) {
        return null;
    }

    $id = (int) $str;
    return $id > 0 ? $id : null;
}

// ----------------------------
// Helper: attach ingredients list to each food row
// ----------------------------
function attachIngredients(PDO $pdo, array $foods): array {
    $ingStmt = $pdo->prepare("
        SELECT i.ingredient_name
        FROM food_ingredients fi
        JOIN ingredients i ON fi.ingredient_id = i.ingredient_id
        WHERE fi.food_id = ?
        ORDER BY i.ingredient_name
    ");

    foreach ($foods as &$food) {
        $ingStmt->execute([$food['food_id']]);
        $food['ingredients'] = array_column($ingStmt->fetchAll(), 'ingredient_name');
    }

    return $foods;
}

// ----------------------------
// Helper: validate & sanitize food write payload
// ----------------------------
function validateFoodPayload(PDO $pdo, ?array $data): array {
    if (!is_array($data)) {
        return [
            'ok'      => false,
            'message' => 'Invalid request body.'
        ];
    }

    $foodName      = isset($data['food_name']) ? sanitizeString((string) $data['food_name']) : '';
    $instructions  = isset($data['instructions']) ? sanitizeString((string) $data['instructions']) : '';
    $categoryId    = $data['category_id'] ?? null;
    $originId      = $data['origin_id'] ?? null;
    $ingredientIds = $data['ingredient_ids'] ?? [];

    if ($foodName === '' || mb_strlen($foodName) < 2) {
        return [
            'ok'      => false,
            'message' => 'food_name must be at least 2 characters.'
        ];
    }

    if (mb_strlen($foodName) > 255) {
        return [
            'ok'      => false,
            'message' => 'food_name must be at most 255 characters.'
        ];
    }

    if ($instructions === '') {
        return [
            'ok'      => false,
            'message' => 'instructions is required.'
        ];
    }

    if (filter_var($categoryId, FILTER_VALIDATE_INT) === false) {
        return [
            'ok'      => false,
            'message' => 'category_id must be a valid integer.'
        ];
    }
    $categoryId = (int) $categoryId;

    if (filter_var($originId, FILTER_VALIDATE_INT) === false) {
        return [
            'ok'      => false,
            'message' => 'origin_id must be a valid integer.'
        ];
    }
    $originId = (int) $originId;

    $catCheck = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = ?");
    $catCheck->execute([$categoryId]);
    if (!$catCheck->fetch()) {
        return [
            'ok'      => false,
            'message' => 'Invalid category_id: category does not exist.'
        ];
    }

    $originCheck = $pdo->prepare("SELECT origin_id FROM origins WHERE origin_id = ?");
    $originCheck->execute([$originId]);
    if (!$originCheck->fetch()) {
        return [
            'ok'      => false,
            'message' => 'Invalid origin_id: origin does not exist.'
        ];
    }

    if ($ingredientIds === null) {
        $ingredientIds = [];
    }

    if (!is_array($ingredientIds)) {
        return [
            'ok'      => false,
            'message' => 'ingredient_ids must be an array of integers.'
        ];
    }

    $validatedIngredientIds = [];
    $ingCheck = $pdo->prepare("SELECT ingredient_id FROM ingredients WHERE ingredient_id = ?");

    foreach ($ingredientIds as $ingredientId) {
        if (filter_var($ingredientId, FILTER_VALIDATE_INT) === false) {
            return [
                'ok'      => false,
                'message' => 'Each ingredient_id must be a valid integer.'
            ];
        }

        $ingredientId = (int) $ingredientId;
        $ingCheck->execute([$ingredientId]);
        if (!$ingCheck->fetch()) {
            return [
                'ok'      => false,
                'message' => "Invalid ingredient_id: {$ingredientId} does not exist."
            ];
        }

        $validatedIngredientIds[] = $ingredientId;
    }

    return [
        'ok' => true,
        'data' => [
            'food_name'      => $foodName,
            'instructions'   => $instructions,
            'category_id'    => $categoryId,
            'origin_id'      => $originId,
            'ingredient_ids' => $validatedIngredientIds,
        ]
    ];
}

// ----------------------------
// C. Token-Based Security Middleware
// ----------------------------
function requireToken(Request $request, RequestHandler $handler): Response {
    $authHeader = $request->getHeaderLine('Authorization');

    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return jsonResponse(new SlimResponse(), [
            'status'  => 'error',
            'message' => 'Unauthorized access. Valid API token is required.'
        ], 401);
    }

    $token = trim($matches[1]);

    if ($token !== API_TOKEN) {
        return jsonResponse(new SlimResponse(), [
            'status'  => 'error',
            'message' => 'Unauthorized access. Valid API token is required.'
        ], 401);
    }

    // Token valid — pass control to the actual route
    return $handler->handle($request);
}

// ----------------------------
// D. Rate Limiting Middleware (per client IP)
// ----------------------------
function rateLimit(Request $request, RequestHandler $handler): Response {
    $maxRequests = defined('RATE_LIMIT_MAX')
        ? (int) RATE_LIMIT_MAX
        : (int) (getenv('RATE_LIMIT_MAX') !== false && getenv('RATE_LIMIT_MAX') !== ''
            ? getenv('RATE_LIMIT_MAX')
            : 120);
    $windowSeconds = defined('RATE_LIMIT_WINDOW')
        ? (int) RATE_LIMIT_WINDOW
        : (int) (getenv('RATE_LIMIT_WINDOW') !== false && getenv('RATE_LIMIT_WINDOW') !== ''
            ? getenv('RATE_LIMIT_WINDOW')
            : 60);

    // Allow disabling via RATE_LIMIT_MAX=0
    if ($maxRequests <= 0 || $windowSeconds <= 0) {
        return $handler->handle($request);
    }

    $serverParams = $request->getServerParams();
    $ip = $serverParams['REMOTE_ADDR'] ?? 'unknown';
    $key = hash('sha256', $ip);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'filipino_cookbook_rate_limit';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    $now = time();
    $windowStart = $now - $windowSeconds;
    $timestamps = [];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $ts) {
                if (is_int($ts) && $ts >= $windowStart) {
                    $timestamps[] = $ts;
                }
            }
        }
    }

    if (count($timestamps) >= $maxRequests) {
        $oldest = $timestamps[0] ?? $now;
        $retryAfter = max(1, ($oldest + $windowSeconds) - $now);
        $response = jsonResponse(new SlimResponse(), [
            'status'  => 'error',
            'message' => 'Too many requests. Please try again later.'
        ], 429);
        return $response
            ->withHeader('Retry-After', (string) $retryAfter)
            ->withHeader('X-RateLimit-Limit', (string) $maxRequests)
            ->withHeader('X-RateLimit-Remaining', '0');
    }

    $timestamps[] = $now;
    @file_put_contents($file, json_encode(array_values($timestamps)), LOCK_EX);

    $response = $handler->handle($request);
    $remaining = max(0, $maxRequests - count($timestamps));

    return $response
        ->withHeader('X-RateLimit-Limit', (string) $maxRequests)
        ->withHeader('X-RateLimit-Remaining', (string) $remaining);
}

// Apply rate limiting to all routes (runs before route handlers)
$app->add('rateLimit');

// ============================================================
// 1. Public Welcome Route (no token required)
// ============================================================
$app->get('/', function (Request $request, Response $response) {
    return jsonResponse($response, [
        'message' => 'Welcome to the Secured Filipino Cookbook API',
        'note'    => 'Use a valid Bearer token to access /api endpoints.'
    ]);
});

// ============================================================
// Group all /api routes and protect them with the token middleware
// ============================================================
$app->group('/api', function ($group) {

    // --------------------------------------------------------
    // 2. Get All Foods
    // --------------------------------------------------------
    $group->get('/foods', function (Request $request, Response $response) {
        try {
            $pdo = getDbConnection();

            $stmt = $pdo->query("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                ORDER BY f.food_id
            ");
            $foods = attachIngredients($pdo, $stmt->fetchAll());

            return jsonResponse($response, $foods);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 3. Search Food by Name  (BEFORE /foods/{id})
    // --------------------------------------------------------
    $group->get('/foods/search/{name}', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $name = sanitizeString((string) ($args['name'] ?? ''));

            if ($name === '') {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Search name is required.'
                ], 400);
            }

            $stmt = $pdo->prepare("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                WHERE f.food_name LIKE ?
                ORDER BY f.food_name
            ");
            $stmt->execute(['%' . $name . '%']);
            $foods = $stmt->fetchAll();

            if (empty($foods)) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Food not found'
                ], 404);
            }

            return jsonResponse($response, attachIngredients($pdo, $foods));
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 4. Get Foods by Category Name  (BEFORE /foods/{id})
    // --------------------------------------------------------
    $group->get('/foods/category/{name}', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $name = sanitizeString((string) ($args['name'] ?? ''));

            if ($name === '') {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Category name is required.'
                ], 400);
            }

            $catStmt = $pdo->prepare("
                SELECT category_id FROM categories WHERE category_name = ?
            ");
            $catStmt->execute([$name]);
            if (!$catStmt->fetch()) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Category not found'
                ], 404);
            }

            $stmt = $pdo->prepare("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                WHERE c.category_name = ?
                ORDER BY f.food_name
            ");
            $stmt->execute([$name]);
            $foods = $stmt->fetchAll();

            if (empty($foods)) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'No foods found for this category'
                ], 404);
            }

            return jsonResponse($response, attachIngredients($pdo, $foods));
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 5. Get Foods by Origin Name  (BEFORE /foods/{id})
    // --------------------------------------------------------
    $group->get('/foods/origin/{name}', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $name = sanitizeString((string) ($args['name'] ?? ''));

            if ($name === '') {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Origin name is required.'
                ], 400);
            }

            $originStmt = $pdo->prepare("
                SELECT origin_id FROM origins WHERE origin_name = ?
            ");
            $originStmt->execute([$name]);
            if (!$originStmt->fetch()) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Origin not found'
                ], 404);
            }

            $stmt = $pdo->prepare("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                WHERE o.origin_name = ?
                ORDER BY f.food_name
            ");
            $stmt->execute([$name]);
            $foods = $stmt->fetchAll();

            if (empty($foods)) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'No foods found for this origin'
                ], 404);
            }

            return jsonResponse($response, attachIngredients($pdo, $foods));
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 5b. Get a Random Filipino Food  (BEFORE /foods/{id})
    // --------------------------------------------------------
    $group->get('/foods/random', function (Request $request, Response $response) {
        try {
            $pdo = getDbConnection();

            $stmt = $pdo->query("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                ORDER BY RAND()
                LIMIT 1
            ");
            $food = $stmt->fetch();

            if (!$food) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'No foods available'
                ], 404);
            }

            $foods = attachIngredients($pdo, [$food]);
            return jsonResponse($response, $foods[0]);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 6. Get Food by ID  (AFTER static /foods/... routes)
    // --------------------------------------------------------
    $group->get('/foods/{id}', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $id = parsePositiveIntId($args['id'] ?? null);

            if ($id === null) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Invalid food ID.'
                ], 400);
            }

            $stmt = $pdo->prepare("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                WHERE f.food_id = ?
            ");
            $stmt->execute([$id]);
            $food = $stmt->fetch();

            if (!$food) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Food not found'
                ], 404);
            }

            $foods = attachIngredients($pdo, [$food]);
            return jsonResponse($response, $foods[0]);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 7. Get All Categories
    // --------------------------------------------------------
    $group->get('/categories', function (Request $request, Response $response) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->query("SELECT * FROM categories ORDER BY category_id");
            return jsonResponse($response, $stmt->fetchAll());
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 7b. Get Number of Foods Under Each Category
    // --------------------------------------------------------
    $group->get('/categories/counts', function (Request $request, Response $response) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->query("
                SELECT
                    c.category_id,
                    c.category_name,
                    COUNT(f.food_id) AS food_count
                FROM categories c
                LEFT JOIN foods f ON f.category_id = c.category_id
                GROUP BY c.category_id, c.category_name
                ORDER BY c.category_id
            ");
            $rows = $stmt->fetchAll();

            // Ensure food_count is an integer in the JSON response
            foreach ($rows as &$row) {
                $row['food_count'] = (int) $row['food_count'];
            }
            unset($row);

            return jsonResponse($response, $rows);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 8. Get All Ingredients
    // --------------------------------------------------------
    $group->get('/ingredients', function (Request $request, Response $response) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->query("SELECT * FROM ingredients ORDER BY ingredient_id");
            return jsonResponse($response, $stmt->fetchAll());
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 9. Get Foods by Ingredient ID
    // --------------------------------------------------------
    $group->get('/ingredients/{id}/foods', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $id = parsePositiveIntId($args['id'] ?? null);

            if ($id === null) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Invalid ingredient ID.'
                ], 400);
            }

            $ingCheck = $pdo->prepare("SELECT ingredient_id FROM ingredients WHERE ingredient_id = ?");
            $ingCheck->execute([$id]);
            if (!$ingCheck->fetch()) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Ingredient not found'
                ], 404);
            }

            $stmt = $pdo->prepare("
                SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                FROM foods f
                INNER JOIN food_ingredients fi ON f.food_id = fi.food_id
                LEFT JOIN categories c ON f.category_id = c.category_id
                LEFT JOIN origins o ON f.origin_id = o.origin_id
                WHERE fi.ingredient_id = ?
                ORDER BY f.food_name
            ");
            $stmt->execute([$id]);
            $foods = $stmt->fetchAll();

            if (empty($foods)) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'No foods found for this ingredient'
                ], 404);
            }

            return jsonResponse($response, attachIngredients($pdo, $foods));
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 10. Add New Food
    // --------------------------------------------------------
    $group->post('/foods', function (Request $request, Response $response) {
        try {
            $pdo = getDbConnection();
            $data = $request->getParsedBody();

            $validation = validateFoodPayload($pdo, $data);
            if (!$validation['ok']) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => $validation['message']
                ], 400);
            }

            $payload = $validation['data'];

            // food_id is not AUTO_INCREMENT — use next available ID
            $newFoodId = (int) $pdo->query("SELECT COALESCE(MAX(food_id), 0) FROM foods")->fetchColumn() + 1;

            $stmt = $pdo->prepare("
                INSERT INTO foods (food_id, food_name, category_id, origin_id, instructions)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $newFoodId,
                $payload['food_name'],
                $payload['category_id'],
                $payload['origin_id'],
                $payload['instructions']
            ]);

            if (count($payload['ingredient_ids']) > 0) {
                $linkStmt = $pdo->prepare("
                    INSERT INTO food_ingredients (food_id, ingredient_id) VALUES (?, ?)
                ");
                foreach ($payload['ingredient_ids'] as $ingredientId) {
                    $linkStmt->execute([$newFoodId, $ingredientId]);
                }
            }

            return jsonResponse($response, [
                'status'  => 'success',
                'message' => 'Food added successfully.',
                'food_id' => $newFoodId
            ], 201);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 11. Update Food
    // --------------------------------------------------------
    $group->put('/foods/{id}', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $id = parsePositiveIntId($args['id'] ?? null);

            if ($id === null) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Invalid food ID.'
                ], 400);
            }

            $check = $pdo->prepare("SELECT food_id FROM foods WHERE food_id = ?");
            $check->execute([$id]);
            if (!$check->fetch()) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Food not found'
                ], 404);
            }

            $data = $request->getParsedBody();
            $validation = validateFoodPayload($pdo, $data);
            if (!$validation['ok']) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => $validation['message']
                ], 400);
            }

            $payload = $validation['data'];

            $stmt = $pdo->prepare("
                UPDATE foods
                SET food_name = ?, category_id = ?, origin_id = ?, instructions = ?
                WHERE food_id = ?
            ");
            $stmt->execute([
                $payload['food_name'],
                $payload['category_id'],
                $payload['origin_id'],
                $payload['instructions'],
                $id
            ]);

            // Always replace ingredient links when ingredient_ids is provided as an array
            // (validateFoodPayload normalizes missing/null to []). Preserve prior behavior:
            // if client omitted ingredient_ids, treat as empty replace only when key present.
            $rawBody = is_array($data) ? $data : [];
            if (array_key_exists('ingredient_ids', $rawBody)) {
                $pdo->prepare("DELETE FROM food_ingredients WHERE food_id = ?")->execute([$id]);

                if (count($payload['ingredient_ids']) > 0) {
                    $linkStmt = $pdo->prepare("
                        INSERT INTO food_ingredients (food_id, ingredient_id) VALUES (?, ?)
                    ");
                    foreach ($payload['ingredient_ids'] as $ingredientId) {
                        $linkStmt->execute([$id, $ingredientId]);
                    }
                }
            }

            return jsonResponse($response, [
                'status'  => 'success',
                'message' => 'Food updated successfully.'
            ]);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

    // --------------------------------------------------------
    // 12. Delete Food
    // --------------------------------------------------------
    $group->delete('/foods/{id}', function (Request $request, Response $response, array $args) {
        try {
            $pdo = getDbConnection();
            $id = parsePositiveIntId($args['id'] ?? null);

            if ($id === null) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Invalid food ID.'
                ], 400);
            }

            $check = $pdo->prepare("SELECT food_id FROM foods WHERE food_id = ?");
            $check->execute([$id]);
            if (!$check->fetch()) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => 'Food not found'
                ], 404);
            }

            $pdo->prepare("DELETE FROM food_ingredients WHERE food_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM foods WHERE food_id = ?")->execute([$id]);

            return jsonResponse($response, [
                'status'  => 'success',
                'message' => 'Food deleted successfully.'
            ]);
        } catch (Throwable $e) {
            return serverErrorResponse($response);
        }
    });

})->add('requireToken'); // apply token middleware to the whole /api group

$app->run();
