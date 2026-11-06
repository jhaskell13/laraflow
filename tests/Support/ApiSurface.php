<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use BackedEnum;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * The Public API, reduced to the shape a baseline can compare.
 *
 * Every **Public** class — the stability marker decides, as it does
 * everywhere else — with the members an application can see: what it
 * extends and implements, its own public (and, where it may be
 * subclassed, protected) methods with their full signatures, its
 * constants, its public properties, and its enum cases.
 *
 * The rules, each with a reason:
 *
 * - **Only members the class declares itself, in this package.** An
 *   inherited method is recorded on the base that declares it, so one
 *   change shows up once rather than on every subclass; inheritance
 *   itself is recorded, so losing a base is still caught. A method
 *   reached through a *trait* reports the using class as its declarer,
 *   so `ExecuteWorkflowJob` appeared to declare Laravel's `onQueue()`
 *   and `withDeduplicator()` — which would have frozen the framework's
 *   API as this package's and failed this test the next time Laravel
 *   changed one. Methods whose file lies outside `src/` are therefore
 *   skipped. Properties have no file of their own, so the traits the
 *   class uses are walked instead and any property one of them
 *   declares from outside `src/` is skipped: `Queueable`'s
 *   `$deduplicator` and `$messageGroup` exist on Laravel 13 and not on
 *   12, and recording them made the baseline pass on one framework
 *   version and fail on the other (found by CI, Milestone 12).
 * - **Protected members only on classes that are not final.** On a
 *   final class nothing outside can see them; on a base designed for
 *   extension they are the extension contract.
 * - **Private members never**, and neither are members a docblock tags
 *   `@internal`. A Public class may carry machinery an application must
 *   not call — `Wait` builds the reserved child and join signals the
 *   runner parks on — and freezing that by accident would mean carrying
 *   it to 2.0. The tag is the way to say so, and it is only honest when
 *   the documentation never promised the member and nothing outside the
 *   package calls it.
 * - **Constant values, not just names.** A value can be as contractual
 *   as a signature, and where one legitimately moves — a connector's
 *   pinned provider API version — compatibility.md already calls that a
 *   minor release "noted in the changelog with what changed". A baseline
 *   diff is what makes someone write the note.
 * - **Enum cases with their backing values.** Those strings are in the
 *   database and in every `--json` document; they are the contract.
 * - **A relative type is rendered relatively.** PHP 8.5 resolves `self`
 *   to the fully qualified class name, and `parent` to the parent's,
 *   where 8.2 to 8.4 report the keyword — so the same source produced
 *   two different baselines and the newest supported PHP failed this
 *   test against one generated on an older version (found by CI,
 *   Milestone 12). A type naming the class it is declared on is
 *   recorded as `self` and one naming that class's parent as `parent`,
 *   on every version; the keyword and the name mean the same thing,
 *   and `extends` is recorded separately, so a parent that changes is
 *   still caught. `static` is reported as `static` everywhere and
 *   needs no help; so do constant defaults.
 */
final class ApiSurface
{
    /**
     * @return array<string, mixed> class name => its shape, both sorted
     */
    public static function build(string $srcPath): array
    {
        $surface = [];

        foreach (self::classesIn($srcPath) as $class) {
            $reflection = new ReflectionClass($class);

            if (self::levelOf($reflection) !== 'Public') {
                continue;
            }

            $surface[$class] = self::describe($reflection, $srcPath);
        }

        ksort($surface);

        return $surface;
    }

    /**
     * The stability level of a class, read from its docblock as a tag.
     *
     * @param  ReflectionClass<object>  $class
     */
    public static function levelOf(ReflectionClass $class): string
    {
        $doc = (string) $class->getDocComment();

        return match (true) {
            self::hasTag($doc, '@internal') => 'Internal',
            self::hasTag($doc, '@experimental') => 'Experimental',
            default => 'Public',
        };
    }

    public static function hasTag(string $docblock, string $tag): bool
    {
        return preg_match('/^\s*\*\s*'.preg_quote($tag, '/').'\b/m', $docblock) === 1;
    }

    /**
     * @return list<class-string>
     */
    public static function classesIn(string $srcPath): array
    {
        $classes = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcPath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($items as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'Laraflow\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                mb_substr((string) $file->getRealPath(), mb_strlen($srcPath) + 1),
            );

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, mixed>
     */
    private static function describe(ReflectionClass $class, string $srcPath): array
    {
        $shape = ['kind' => self::kindOf($class)];
        $parentName = ($parent = $class->getParentClass()) === false ? null : $parent->getName();

        if ($parentName !== null) {
            $shape['extends'] = $parentName;
        }

        $interfaces = $class->getInterfaceNames();
        sort($interfaces);

        if ($interfaces !== []) {
            $shape['implements'] = $interfaces;
        }

        // Protected members are part of the contract only where a class
        // can be subclassed at all.
        $visibility = ReflectionMethod::IS_PUBLIC | ($class->isFinal() ? 0 : ReflectionMethod::IS_PROTECTED);

        $methods = [];

        foreach ($class->getMethods($visibility) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            if (self::hasTag((string) $method->getDocComment(), '@internal') || ! self::declaredIn($method->getFileName(), $srcPath)) {
                continue;
            }

            $methods[$method->getName()] = self::signature($method, $class->getName(), $parentName);
        }

        ksort($methods);

        if ($methods !== []) {
            $shape['methods'] = $methods;
        }

        $constants = [];

        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC | ($class->isFinal() ? 0 : ReflectionClassConstant::IS_PROTECTED)) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class->getName() || $constant->isEnumCase()) {
                continue;
            }

            if (self::hasTag((string) $constant->getDocComment(), '@internal')) {
                continue;
            }

            $constants[$constant->getName()] = self::render($constant->getValue());
        }

        ksort($constants);

        if ($constants !== []) {
            $shape['constants'] = $constants;
        }

        $properties = [];
        $foreign = self::foreignTraitProperties($class, $srcPath);

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC | ($class->isFinal() ? 0 : ReflectionProperty::IS_PROTECTED)) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            if (self::hasTag((string) $property->getDocComment(), '@internal')) {
                continue;
            }

            if (isset($foreign[$property->getName()])) {
                continue;
            }

            $properties[$property->getName()] = ($property->isReadOnly() ? 'readonly ' : '')
                .($property->isStatic() ? 'static ' : '')
                .self::type($property->getType(), $class->getName(), $parentName);
        }

        ksort($properties);

        if ($properties !== []) {
            $shape['properties'] = $properties;
        }

        if ($class->isEnum()) {
            $cases = [];
            /** @var class-string<UnitEnum> $enum */
            $enum = $class->getName();

            foreach ((new ReflectionEnum($enum))->getCases() as $case) {
                $value = $case->getValue();
                $cases[$case->getName()] = $value instanceof BackedEnum ? self::render($value->value) : null;
            }

            ksort($cases);
            $shape['cases'] = $cases;
        }

        return $shape;
    }

    /**
     * The names of every property a class receives from a trait that
     * is not this package's, walking traits that use traits. A trait
     * property reports the using class as its declarer and has no file
     * of its own, so this is the only way to tell it from one the class
     * declares itself.
     *
     * @param  ReflectionClass<object>  $class
     * @return array<string, true>
     */
    private static function foreignTraitProperties(ReflectionClass $class, string $srcPath): array
    {
        $names = [];
        $pending = array_values($class->getTraits());

        while (($trait = array_pop($pending)) !== null) {
            foreach ($trait->getTraits() as $nested) {
                $pending[] = $nested;
            }

            if (self::declaredIn($trait->getFileName(), $srcPath)) {
                continue;
            }

            foreach ($trait->getProperties() as $property) {
                $names[$property->getName()] = true;
            }
        }

        return $names;
    }

    /**
     * Whether a member's own file belongs to this package — false for
     * anything reached through a third-party trait.
     */
    private static function declaredIn(string|false $file, string $srcPath): bool
    {
        return is_string($file) && str_starts_with($file, $srcPath.DIRECTORY_SEPARATOR);
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private static function kindOf(ReflectionClass $class): string
    {
        return match (true) {
            $class->isInterface() => 'interface',
            $class->isEnum() => 'enum',
            $class->isTrait() => 'trait',
            $class->isAbstract() => 'abstract class',
            $class->isFinal() => 'final class',
            default => 'class',
        };
    }

    private static function signature(ReflectionMethod $method, string $declaredOn, ?string $parentOf): string
    {
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => self::parameter($parameter, $declaredOn, $parentOf),
            $method->getParameters(),
        );

        return ($method->isProtected() ? 'protected ' : '')
            .($method->isStatic() ? 'static ' : '')
            .($method->isAbstract() && ! $method->getDeclaringClass()->isInterface() ? 'abstract ' : '')
            .($method->isFinal() ? 'final ' : '')
            .$method->getName().'('.implode(', ', $parameters).'): '.self::type($method->getReturnType(), $declaredOn, $parentOf);
    }

    private static function parameter(ReflectionParameter $parameter, string $declaredOn, ?string $parentOf): string
    {
        $rendered = self::type($parameter->getType(), $declaredOn, $parentOf).' '
            .($parameter->isPassedByReference() ? '&' : '')
            .($parameter->isVariadic() ? '...' : '')
            .'$'.$parameter->getName();

        if (! $parameter->isDefaultValueAvailable()) {
            return $rendered;
        }

        // A constant default is recorded by name: the name is what the
        // signature says, and its value is frozen as a constant anyway.
        if ($parameter->isDefaultValueConstant()) {
            return $rendered.' = '.(string) $parameter->getDefaultValueConstantName();
        }

        return $rendered.' = '.self::render($parameter->getDefaultValue());
    }

    private static function type(?ReflectionType $type, string $declaredOn, ?string $parentOf = null): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof ReflectionNamedType) {
            // PHP 8.5 hands back the resolved class name where earlier
            // versions hand back the keyword. They mean the same
            // thing, so the keyword is recorded on every version.
            $name = match ($type->getName()) {
                $declaredOn => 'self',
                $parentOf => 'parent',
                default => $type->getName(),
            };

            return ($type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' : '').$name;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $parts = array_map(
                static fn (ReflectionType $part): string => self::type($part, $declaredOn, $parentOf),
                $type->getTypes(),
            );
            sort($parts);

            return implode($type instanceof ReflectionUnionType ? '|' : '&', $parts);
        }

        return (string) $type;
    }

    private static function render(mixed $value): string
    {
        if ($value instanceof UnitEnum) {
            return $value::class.'::'.$value->name;
        }

        $encoded = json_encode($value);

        return $encoded === false ? gettype($value) : $encoded;
    }
}
