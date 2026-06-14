# Source Code Documentation Tools

Reference for generating browsable documentation directly from source code across our stack.

## PHP (service-virtualization)

### phpDocumentor

The standard PHP documentation generator. Reads `/** ... */` docblock annotations and produces static HTML.

**Installed:** Yes, via `phpdocumentor/shim` in `composer.json` (dev dependency).

**Generate:**
```bash
vendor/bin/phpdoc run -d ./src -t ./docs/api
```

**View:** Open `docs/api/index.html` in a browser, or serve locally:
```bash
php -S localhost:8000 -t ./docs/api
```

**Output:** Static HTML with searchable index, class hierarchy, and namespace navigation. Output is gitignored (`docs/api/`).

**Docblock format:**
```php
/**
 * Short summary of the class or method.
 *
 * Longer description if needed.
 *
 * @param string $name  Parameter description
 * @return array        Return value description
 * @throws \Exception   When something goes wrong
 */
```

**Equivalent in other ecosystems:** `godoc` (Go), `javadoc` (Java).

---

## TypeScript / NestJS (penny-api and other NestJS services)

### TypeDoc

General-purpose TypeScript documentation generator. Clean, modern UI. No awareness of NestJS-specific decorators.

**Install:**
```bash
npm install --save-dev typedoc
```

**Generate:**
```bash
npx typedoc --entryPoints src --entryPointStrategy expand --out docs/typedoc --exclude "**/*.spec.ts" --exclude "**/test/**" --skipErrorChecking
```

**View:** Open `docs/typedoc/index.html` in a browser.

**Strengths:** Modern UI, fast generation, clean output.
**Weaknesses:** No NestJS awareness (doesn't understand modules, injection, decorators). No dependency diagrams.

### Compodoc

NestJS/Angular-aware documentation generator. Understands decorators and generates module dependency diagrams.

**Install:**
```bash
npm install --save-dev @compodoc/compodoc
```

**Generate:**
```bash
npx compodoc -p tsconfig.json -d docs/compodoc
```

**View:** Open `docs/compodoc/index.html` or serve with built-in server:
```bash
npx compodoc -p tsconfig.json -s  # serves at http://localhost:8080
```

**Strengths:**
- Understands NestJS module structure, controllers, services, guards, pipes, entities
- Generates interactive dependency diagrams showing how modules wire together
- Tooltip info buttons that link to definitions of concepts
- Shows the injection tree

**Weaknesses:** Dated UI (uses a "gitbook" theme that hasn't aged well).

**Available themes:** `laravel`, `material`, `postmark`, `original`, `stripe`
```bash
npx compodoc -p tsconfig.json -d docs/compodoc --theme material
```

### Recommendation

For NestJS projects, **Compodoc is the better choice** despite its dated UI. The NestJS-aware features (module diagrams, injection tree, decorator awareness, interactive tooltips linking to definitions) are genuinely useful and not available in TypeDoc. TypeDoc produces nicer-looking output but misses the architectural context that matters most when navigating a NestJS codebase.

### dependency-cruiser (not recommended)

Tested as an alternative for dependency graphs. Generates DOT/SVG/HTML output showing file-level dependencies. Requires Graphviz for SVG output. On penny-api, even a single module produced a 500MB HTML file, making it impractical for a codebase this size.

---

## Swagger / OpenAPI (different purpose)

These tools document **API endpoints** (URLs, request/response schemas, auth) for external consumers. This is different from source code documentation, which documents **internal architecture** for developers working inside the codebase.

- NestJS has first-class Swagger support via `@nestjs/swagger` with decorators like `@ApiProperty()`, `@ApiResponse()`
- PHP equivalent would be annotations like `@OA\Post(path="/...")` with the `swagger-php` library
- Some AlfredPay services already expose Swagger via `swaggerUrl` in `repos.json`

---

## General Workflow

1. Source code with docblocks/JSDoc/TSDoc lives in the repo
2. Generated output is gitignored (reproducible from source)
3. Generate locally when needed; view in browser
4. For team sharing, CI pipeline generates and deploys to a docs host (GitHub Pages, S3, internal Nginx)
