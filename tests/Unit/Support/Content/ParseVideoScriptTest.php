<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\ParseVideoScript;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParseVideoScriptTest extends TestCase
{
    #[Test]
    public function it_parses_talking_points_and_script_blocks(): void
    {
        $markdown = <<<'MD'
# Test video

**Language:** Bangla · **Est. length:** 45s

## Talking points

1. **Hook** - Open with the bill
2. Same question twice

---

```
bn
[HOOK]
Line one [pause]
Line two
```

## 📣 Captions

### Facebook

**Caption:**
Hello

## Fact-check

- Claim one ✅
Sources: example.com

## Legal Note

- Not financial advice
MD;

        $parsed = ParseVideoScript::fromMarkdown($markdown, 'bn');

        $this->assertSame('Bangla', $parsed['lang']);
        $this->assertSame('45s', $parsed['length']);
        $this->assertCount(2, $parsed['points']);
        $this->assertSame('Hook', $parsed['points'][0]['label']);
        $this->assertCount(1, $parsed['scripts']);
        $this->assertSame('Bangla', $parsed['scripts'][0]['lang']);
        $this->assertStringContainsString('Line one', $parsed['scripts'][0]['body']);
        $this->assertSame(['Claim one ✅'], $parsed['facts']);
        $this->assertSame('example.com', $parsed['sources']);
        $this->assertSame(['Not financial advice'], $parsed['legal']);
    }
}
