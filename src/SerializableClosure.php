<?php namespace SuperClosure;

use SuperClosure\Exception\ClosureUnserializationException;

/**
 * This class acts as a wrapper for a closure, and allows it to be serialized.
 *
 * With the combined power of the Reflection API, code parsing, and the infamous
 * `eval()` function, you can serialize a closure, unserialize it somewhere
 * else (even a different PHP process), and execute it.
 */
class SerializableClosure implements \Serializable
{
    /**
     * The closure being wrapped for serialization.
     *
     * @var \Closure
     */
    private $closure;

    /**
     * The serializer doing the serialization work.
     *
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * The data from unserialization.
     *
     * @var array
     */
    private $data;

    /**
     * Create a new serializable closure instance.
     *
     * @param \Closure                 $closure
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        \Closure $closure,
        SerializerInterface $serializer = null
    ) {
        $this->closure = $closure;
        $this->serializer = $serializer ?: new Serializer;
    }

    /**
     * Return the original closure object.
     *
     * @return \Closure
     */
    public function getClosure()
    {
        return $this->closure;
    }

    /**
     * Delegates the closure invocation to the actual closure object.
     *
     * Important Notes:
     *
     * - `ReflectionFunction::invokeArgs()` should not be used here, because it
     *   does not work with closure bindings.
     * - Args passed-by-reference lose their references when proxied through
     *   `__invoke()`. This is an unfortunate, but understandable, limitation
     *   of PHP that will probably never change.
     *
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func_array($this->closure, func_get_args());
    }

    /**
     * Clones the SerializableClosure with a new bound object and class scope.
     *
     * The method is essentially a wrapped proxy to the \Closure::bindTo method.
     *
     * @param mixed $newthis  The object to which the closure should be bound,
     *                        or NULL for the closure to be unbound.
     * @param mixed $newscope The class scope to which the closure is to be
     *                        associated, or 'static' to keep the current one.
     *                        If an object is given, the type of the object will
     *                        be used instead. This determines the visibility of
     *                        protected and private methods of the bound object.
     *
     * @return SerializableClosure
     * @link http://www.php.net/manual/en/closure.bindto.php
     */
    public function bindTo($newthis, $newscope = 'static')
    {
        return new self(
            $this->closure->bindTo($newthis, $newscope),
            $this->serializer
        );
    }

    /**
     * Serializes the code, context, and binding of the closure.
     *
     * @return string|null
     * @link http://php.net/manual/en/serializable.serialize.php
     */
    public function serialize()
    {
        try {
            $this->data = $this->data ?: $this->serializer->getData($this->closure, true);
            return serialize($this->data);
        } catch (\Exception $e) {
            trigger_error(
                'Serialization of closure failed: ' . $e->getMessage(),
                E_USER_NOTICE
            );
            // Note: The serialize() method of Serializable must return a string
            // or null and cannot throw exceptions.
            return null;
        }
    }

    /**
     * Unserializes the closure.
     *
     * Unserializes the closure's data and recreates the closure using a
     * simulation of its original context. The used variables (context) are
     * extracted into a fresh scope prior to redefining the closure. The
     * closure is also rebound to its former object and scope.
     *
     * @param string $serialized
     *
     * @throws ClosureUnserializationException
     * @link http://php.net/manual/en/serializable.unserialize.php
     */
    public function unserialize($serialized)
    {
        // Unserialize the data and reconstruct the SuperClosure.
        $this->data = unserialize($serialized);
        try {
            $this->reconstructClosure();
        } catch (\ParseException $e) {
            // Discard the parse exception, we'll throw a custom one
            // a few lines down.
        }
        if (!$this->closure instanceof \Closure) {
            throw new ClosureUnserializationException(
                'The closure is corrupted and cannot be unserialized.'
            );
        }

        // Rebind the closure to its former binding, if it's not static.
        if (!$this->data['isStatic']) {
            $this->closure = $this->closure->bindTo(
                $this->data['binding'],
                $this->data['scope']
            );
        }
    }

    /**
     * Reconstruct the closure.
     *
     * HERE BE DRAGONS!
     *
     * The infamous `eval()` is used in this method, along with `extract()`,
     * the error suppression operator, and variable variables (i.e., double
     * dollar signs) to perform the unserialization work. I'm sorry, world!
     */
    private function reconstructClosure()
    {
        // Simulate the original context the closure was created in.
        extract($this->data['context'], EXTR_OVERWRITE);

        // Evaluate the code to recreate the closure.
        if ($_fn = array_search(Serializer::RECURSION, $this->data['context'], true)) {

            // Eval work around
            if(!$this->evalEnabled()) {
                $secretKey = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 1).substr(md5(time()), 1);
                $phpCode = "<?php if(\$secretKey != \"" . $secretKey . "\") return;";
                $phpCode .= "\${$_fn} = {$this->data['code']};";
                $phpCode .= "?>";
                $temp_file = tempnam(sys_get_temp_dir(), 'eval');
                // Write code to file and include it to execute it instead of calling eval
                file_put_contents($temp_file, $phpCode);
                include($temp_file);
                unlink($temp_file);
            }
            else {
                @eval("\${$_fn} = {$this->data['code']};");
            }
            $this->closure = $$_fn;
        } else {
            // Eval work around
            if(!$this->evalEnabled()) {
                $secretKey = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 1).substr(md5(time()), 1);
                $phpCode = "<?php if(\$secretKey != \"" . $secretKey . "\") return;";
                $phpCode .= "\$this->closure = {$this->data['code']};";
                $phpCode .= "?>";
                $temp_file = tempnam(sys_get_temp_dir(), 'eval');
                // Write code to file and include it to execute it instead of calling eval
                file_put_contents($temp_file, $phpCode);
                include($temp_file);
                unlink($temp_file);
            }
            else {
                @eval("\$this->closure = {$this->data['code']};");
            }
        }
    }

    private function evalEnabled() {
        $isEvalFunctionAvailable = false;

        $evalcheck = "\$isEvalFunctionAvailable = true;";

        eval($evalcheck);

        if ($isEvalFunctionAvailable === true) {
            return true;
        }

        return false;
    }

    /**
     * Returns closure data for `var_dump()`.
     *
     * @return array
     */
    public function __debugInfo()
    {
        return $this->data ?: $this->serializer->getData($this->closure, true);
    }
}
