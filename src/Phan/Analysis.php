<?php declare(strict_types=1);
namespace Phan;

use Phan\Analysis\DuplicateFunctionAnalyzer;
use Phan\Analysis\ParameterTypesAnalyzer;
use Phan\Analysis\ReferenceCountsAnalyzer;
use Phan\Language\Context;
use Phan\Language\Element\Func;
use Phan\Language\Element\Method;
use Phan\Parse\ParseVisitor;
use Phan\Plugin\ConfigPluginSet;
use ast\Node;

/**
 * This class is the entry point into the static analyzer.
 */
class Analysis
{
    /**
     * This first pass parses code and looks for the subset
     * of issues that can be found without having to have
     * an understanding of the entire code base.
     *
     * @param CodeBase $code_base
     * The CodeBase represents state across the entire
     * code base. This is a mutable object which is
     * populated as we parse files
     *
     * @param string $file_path
     * The full path to a file we'd like to parse
     *
     * @param bool $suppress_parse_errors
     *
     * @param ?string $override_contents
     * If this is not null, this function will act as if $file_path's contents
     * were $override_contents
     *
     * @return Context
     */
    public static function parseFile(CodeBase $code_base, string $file_path, bool $suppress_parse_errors = false, string $override_contents = null) : Context
    {
        $code_base->setCurrentParsedFile($file_path);
        $context = (new Context)->withFile($file_path);

        // Convert the file to an Abstract Syntax Tree
        // before passing it on to the recursive version
        // of this method
        try {
            if (is_string($override_contents)) {
                $node = \ast\parse_code(
                    $override_contents,
                    Config::get()->ast_version
                );
            } else {
                $node = \ast\parse_file(
                    Config::projectPath($file_path),
                    Config::get()->ast_version
                );
            }
        } catch (\ParseError $parse_error) {
            if ($suppress_parse_errors) {
                return $context;
            }
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::SyntaxError,
                $parse_error->getLine(),
                $parse_error->getMessage()
            );

            return $context;
        }

        if (Config::get()->dump_ast) {
            echo $file_path . "\n"
                . str_repeat("\u{00AF}", strlen($file_path))
                . "\n";
            Debug::printNode($node);
            return $context;
        }

        if (empty($node)) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::EmptyFile,
                0,
                $file_path
            );

            return $context;
        }

        return self::parseNodeInContext(
            $code_base,
            $context,
            $node
        );
    }

    /**
     * Parse the given node in the given context populating
     * the code base within the context as a side effect. The
     * returned context is the new context from within the
     * given node.
     *
     * @param CodeBase $code_base
     * The global code base in which we store all
     * state
     *
     * @param Context $context
     * The context in which this node exists
     *
     * @param Node $node
     * A node to parse and scan for errors
     *
     * @return Context
     * The context from within the node is returned
     */
    public static function parseNodeInContext(CodeBase $code_base, Context $context, Node $node) : Context
    {
        return self::parseNodeInContextInner($code_base, $context, $node, self::shouldVisitEverything());
    }

    /**
     * @return bool - Whether or not every AST should be visited, according to the current config.
     */
    public static function shouldVisitEverything() : bool {
        $config = Config::get();
        return $config->dead_code_detection || $config->should_visit_all_nodes;
    }

    /**
     * @see self::parseNodeInContext
     *
     * @param bool $shouldVisitEverything - Whether or not all AST nodes should be parsed.
     */
    private static function parseNodeInContextInner(CodeBase $code_base, Context $context, Node $node, bool $should_visit_everything) {
        // Save a reference to the outer context
        $outer_context = $context;

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new ParseVisitor(
            $code_base,
            $context->withLineNumberStart($node->lineno ?? 0)
        ))($node);

        assert(!empty($context), 'Context cannot be null');
        $kind = $node->kind;

        // \ast\AST_GROUP_USE has \ast\AST_USE as a child.
        // We don't want to use block twice in the parse phase.
        // (E.g. `use MyNS\{const A, const B}` would lack the MyNs part if this were to recurse.
        if ($kind === \ast\AST_GROUP_USE) {
            return $context;
        }

        // Recurse into each child node
        $child_context = $context;
        foreach ($node->children ?? [] as $child_node) {

            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                continue;
            }

            if (!($should_visit_everything || self::shouldVisitNode($child_node))) {
                $child_context->withLineNumberStart(
                    $child_node->lineno ?? 0
                );
                continue;
            }

            // Step into each child node and get an
            // updated context for the node
            $child_context = self::parseNodeInContextInner($code_base, $child_context, $child_node, $should_visit_everything);

            assert(!empty($child_context), 'Context cannot be null');
        }

        // For closed context elements (that have an inner scope)
        // return the outer context instead of their inner context
        // after we finish parsing their children.
        if (in_array($kind, [
            \ast\AST_CLASS,
            \ast\AST_METHOD,
            \ast\AST_FUNC_DECL,
            \ast\AST_CLOSURE,
        ], true)) {
            return $outer_context;
        }

        // Pass the context back up to our parent
        return $context;
    }

    /**
     * Take a pass over all functions verifying various
     * states.
     *
     * @return void
     */
    public static function analyzeFunctions(CodeBase $code_base, array $file_filter = null)
    {
        $plugin_set = ConfigPluginSet::instance();
        $has_plugins = $plugin_set->hasPlugins();
        $function_count = count($code_base->getFunctionAndMethodSet());
        $show_progress = CLI::shouldShowProgress();
        $i = 0;

        if ($show_progress) { CLI::progress('method', 0.0); }

        foreach ($code_base->getFunctionAndMethodSet() as $function_or_method)
        {
            if ($show_progress) { CLI::progress('method', (++$i)/$function_count); }

            if ($function_or_method->isPHPInternal()) {
                continue;
            }

            // If there is an array limiting the set of files, skip this file if it's not in the list,
            if (is_array($file_filter) && !isset($file_filter[$function_or_method->getContext()->getFile()])) {
                continue;
            }
            DuplicateFunctionAnalyzer::analyzeDuplicateFunction(
                $code_base, $function_or_method
            );

            // This is the most time consuming step.
            // Can probably apply this to other functions, but this was the slowest.
            ParameterTypesAnalyzer::analyzeParameterTypes(
                $code_base, $function_or_method
            );
            // Let any plugins analyze the methods or functions
            // XXX: Add a way to run plugins on all functions/methods, this was limited for speed.
            // Assumes that the given plugins will emit an issue in the same file as the function/method,
            // which isn't necessarily the case.
            // 0.06
            if ($has_plugins) {
                if ($function_or_method instanceof Func) {
                    $plugin_set->analyzeFunction(
                        $code_base, $function_or_method
                    );
                } else if ($function_or_method instanceof Method) {
                    $plugin_set->analyzeMethod(
                        $code_base, $function_or_method
                    );
                }
            }
        }
    }

    /**
     * Take a pass over all classes/traits/interfaces
     * verifying various states.
     *
     * @return void
     */
    public static function analyzeClasses($code_base, array $path_filter = null)
    {
        $classes = $code_base->getClassMap();
        if (is_array($path_filter)) {
            // Convert Map to array. Both are iterable.
            $old_classes = $classes;
            $classes = [];
            foreach ($old_classes as $class) {
                if (!$class->isPHPInternal() && isset($path_filter[$class->getContext()->getFile()])) {
                    $classes[] = $class;
                }
            }
        }
        foreach ($classes as $class) {
            $class->analyze($code_base);
        }
    }

    /**
     * Take a look at all globally accessible elements and see if
     * we can find any dead code that is never referenced
     *
     * @return void
     */
    public static function analyzeDeadCode(CodeBase $code_base)
    {
        // Check to see if dead code detection is enabled. Keep
        // in mind that the results here are just a guess and
        // we can't tell with certainty that anything is
        // definitely unreferenced.
        if (!Config::get()->dead_code_detection) {
            return;
        }

        ReferenceCountsAnalyzer::analyzeReferenceCounts($code_base);
    }

    /**
     * Possible Premature Optimization. We can trim a bunch of
     * calls to nodes that we'll never analyze during parsing,
     * pre-order analysis or post-order analysis.
     *
     * @param Node $node
     * A node we'd like to determine if we should visit
     *
     * @return bool
     * True if the given node should be visited or false if
     * it should be skipped entirely
     *
     * @see self::shouldVisitNode (and self::shouldVisitEverything) for a faster way to check nodes.
     */
    public static function shouldVisit(Node $node)
    {
        // When doing dead code detection, we need to go
        // super deep
        if (self::shouldVisitEverything()) {
            return true;
        }
        return self::shouldVisitNode($node);
    }

    /**
     * @return bool - true if a node should be visited unconditionally, no matter what the value of the Config is.
     *                Assumes the caller already checked the value of self::shouldVisitEverything()
     * @see self::shouldVisit
     */
    public static function shouldVisitNode(Node $node) : bool {
        switch ($node->kind) {
            case \ast\AST_ARRAY_ELEM:
            case \ast\AST_ASSIGN_OP:
            case \ast\AST_BREAK:
            case \ast\AST_CAST:
            case \ast\AST_CLONE:
            case \ast\AST_CLOSURE_USES:
            case \ast\AST_CLOSURE_VAR:
            case \ast\AST_COALESCE:
            case \ast\AST_CONST_ELEM:
            case \ast\AST_CONTINUE:
            case \ast\AST_EMPTY:
            case \ast\AST_EXIT:
            case \ast\AST_INCLUDE_OR_EVAL:
            case \ast\AST_ISSET:
            case \ast\AST_MAGIC_CONST:
            case \ast\AST_NAME:
            case \ast\AST_NAME_LIST:
            case \ast\AST_PARAM:
            case \ast\AST_PARAM_LIST:
            case \ast\AST_POST_INC:
            case \ast\AST_PRE_INC:
            case \ast\AST_STATIC_PROP:
            case \ast\AST_TYPE:
            case \ast\AST_UNARY_OP:
            case \ast\AST_UNSET:
            case \ast\AST_YIELD:
                return false;
        }

        return true;
    }

    /**
     * Once we know what the universe looks like we
     * can scan for more complicated issues.
     *
     * @param CodeBase $code_base
     * The global code base holding all state
     *
     * @param string $file_path
     * A list of files to scan
     *
     * @return Context
     */
    public static function analyzeFile(
        CodeBase $code_base,
        string $file_path,
        string $file_contents_override = null
    ) : Context {
        // Set the file on the context
        $context = (new Context)->withFile($file_path);

        // Convert the file to an Abstract Syntax Tree
        // before passing it on to the recursive version
        // of this method
        try {
            if (is_string($file_contents_override)) {
                $node = \ast\parse_code(
                    $file_contents_override,
                    Config::get()->ast_version
                );
            } else {
                $node = \ast\parse_file(
                    Config::projectPath($file_path),
                    Config::get()->ast_version
                );
            }
        } catch (\ParseError $parse_error) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::SyntaxError,
                $parse_error->getLine(),
                $parse_error->getMessage()
            );
            return $context;
        }

        // Ensure we have some content
        if (empty($node)) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::EmptyFile,
                0,
                $file_path
            );
            return $context;
        }

        return (new BlockAnalysisVisitor($code_base, $context))($node);
    }
}
