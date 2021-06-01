<?php
/**
 * Class for reading datafiles generated by Webgrind_Preprocessor
 *
 * @package Webgrind
 * @author Jacob Oettinger
 */
class Webgrind_Reader
{
    /**
     * File format version that this reader understands
     */
    const FILE_FORMAT_VERSION = 7;

    /**
     * Binary number format used.
     * @see http://php.net/pack
     */
    const NR_FORMAT = 'V';

    /**
     * Size, in bytes, of the above number format
     */
    const NR_SIZE = 4;

    /**
     * Length of a call information block
     */
    const CALLINFORMATION_LENGTH = 4;

    /**
     * Length of a function information block
     */
    const FUNCTIONINFORMATION_LENGTH = 6;

    /**
     * Number of nanoseconds in 1 millisecond
     */
    const NANOSECONDS_IN_MILLISECOND = 1000000;

    /**
     * Number of nanoseconds in 1 microsecond
     */
    const NANOSECONDS_IN_MICROSECOND = 1000;

    /**
     * Number of microseconds in 1 millisecond
     */
    const MICROSECONDS_IN_MILLISECOND = 1000;

    /**
     * Address of the headers in the data file
     *
     * @var int
     */
    private $headersPos;

    /**
     * Array of addresses pointing to information about functions
     *
     * @var array
     */
    private $functionPos;

    /**
     * Array of headers
     *
     * @var array
     */
    private $headers=null;

    /**
     * Format to return costs in
     *
     * @var string
     */
    private $costFormat;

    /**
     * The divisor used to convert the raw time to milliseconds
     *
     * @var int
     */
    private $msecDivisor;

    /**
     * The divisor used to convert the raw time to microseconds
     *
     * @var int
     */
    private $usecDivisor;

    /**
     * Constructor
     * @param string Data file to read
     * @param string Format to return costs in
     */
    function __construct($dataFile, $costFormat) {
        $this->fp = @fopen($dataFile,'rb');
        if (!$this->fp)
            throw new Exception('Error opening file!');

        $this->costFormat = $costFormat;
        $this->init();
    }

    /**
     * Initializes the parser by reading initial information.
     *
     * Throws an exception if the file version does not match the readers version
     *
     * @return void
     * @throws Exception
     */
    private function init() {
        list($version, $this->headersPos, $functionCount) = $this->read(3);
        if ($version!=self::FILE_FORMAT_VERSION)
            throw new Exception('Datafile not correct version. Found '.$version.' expected '.self::FILE_FORMAT_VERSION);
        $this->functionPos = $this->read($functionCount);
        if (!is_array($this->functionPos))
            $this->functionPos = array($this->functionPos);
        $eventsHeader = $this->getHeader('events');

        // Keep current behavior and assume profiles are in microseconds by default
        $this->msecDivisor = self::MICROSECONDS_IN_MILLISECOND;
        $this->usecDivisor = 1;

        // If the time unit is explicitly defined, then set the divisors appropriately.
        if ($eventsHeader !== '' && preg_match('/Time_\(\d*([µn]s)\)/', $eventsHeader, $matches) === 1) {
            if ($matches[1] === 'µs') {
                $this->msecDivisor = self::MICROSECONDS_IN_MILLISECOND;
                $this->usecDivisor = 1;
            } elseif ($matches[1] === 'ns') {
                $this->msecDivisor = self::NANOSECONDS_IN_MILLISECOND;
                $this->usecDivisor = self::NANOSECONDS_IN_MICROSECOND;
            }
        }
    }

    /**
     * Returns number of functions
     * @return int
     */
    function getFunctionCount() {
        return count($this->functionPos);
    }

    /**
     * Returns information about function with nr $nr
     *
     * @param $nr int Function number
     * @return array Function information
     */
    function getFunctionInfo($nr) {
        $this->seek($this->functionPos[$nr]);

        list($line, $summedSelfCost, $summedInclusiveCost, $invocationCount, $calledFromCount, $subCallCount) = $this->read(self::FUNCTIONINFORMATION_LENGTH);

        $this->seek(self::NR_SIZE*self::CALLINFORMATION_LENGTH*($calledFromCount+$subCallCount), SEEK_CUR);
        $file = $this->readLine();
        $function = $this->readLine();

        $result = array(
            'file'                => $file,
            'line'                => $line,
            'functionName'        => $function,
            'summedSelfCost'      => $summedSelfCost,
            'summedInclusiveCost' => $summedInclusiveCost,
            'invocationCount'     => $invocationCount,
            'calledFromInfoCount' => $calledFromCount,
            'subCallInfoCount'    => $subCallCount
        );
        $result['summedSelfCostRaw'] = $result['summedSelfCost'];
        $result['summedSelfCost'] = $this->formatCost($result['summedSelfCost']);
        $result['summedInclusiveCost'] = $this->formatCost($result['summedInclusiveCost']);

        return $result;
    }

    /**
     * Returns information about positions where a function has been called from
     *
     * @param $functionNr int Function number
     * @param $calledFromNr int Called from position nr
     * @return array Called from information
     */
    function getCalledFromInfo($functionNr, $calledFromNr) {
        $this->seek(
            $this->functionPos[$functionNr]
            + self::NR_SIZE
            * (self::CALLINFORMATION_LENGTH * $calledFromNr + self::FUNCTIONINFORMATION_LENGTH)
            );

        $data = $this->read(self::CALLINFORMATION_LENGTH);

        $result = array(
            'functionNr'     => $data[0],
            'line'           => $data[1],
            'callCount'      => $data[2],
            'summedCallCost' => $data[3]
        );

        $result['summedCallCost'] = $this->formatCost($result['summedCallCost']);

        return $result;
    }

    /**
     * Returns information about functions called by a function
     *
     * @param $functionNr int Function number
     * @param $subCallNr int Sub call position nr
     * @return array Sub call information
     */
    function getSubCallInfo($functionNr, $subCallNr) {
        // Sub call count is the second last number in the FUNCTION_INFORMATION block
        $this->seek($this->functionPos[$functionNr] + self::NR_SIZE * (self::FUNCTIONINFORMATION_LENGTH - 2));
        $calledFromInfoCount = $this->read();
        $this->seek( ( ($calledFromInfoCount+$subCallNr) * self::CALLINFORMATION_LENGTH + 1 ) * self::NR_SIZE,SEEK_CUR);
        $data = $this->read(self::CALLINFORMATION_LENGTH);

        $result = array(
            'functionNr'     => $data[0],
            'line'           => $data[1],
            'callCount'      => $data[2],
            'summedCallCost' => $data[3]
        );

        $result['summedCallCost'] = $this->formatCost($result['summedCallCost']);

        return $result;
    }

    /**
     * Returns value of a single header
     *
     * @return string Header value
     */
    function getHeader($header) {
        if ($this->headers==null) { // Cache headers
            $this->seek($this->headersPos);
            $this->headers = array(
                'runs'    => 0,
                'summary' => 0,
                'cmd'     => '',
                'creator' => '',
                'events'  => '',
            );
            while ($line=$this->readLine()) {
                $parts = explode(': ',$line);
                if ($parts[0] == 'summary') {
                    // According to https://github.com/xdebug/xdebug/commit/926808a6e0204f5835a617caa3581b45f6d82a6c#diff-1a570e993c4d7f2e341ba24905b8b2cdR355
                    // summary now includes time + memory usage, webgrind only tracks the time from the summary
                    $subParts = explode(' ', $parts[1]);
                    $this->headers['runs']++;
                    $this->headers['summary'] += (double) $subParts[0];
                } else {
                    $this->headers[$parts[0]] = $parts[1];
                }
            }
        }

        return $this->headers[$header];
    }

    /**
     * Formats $cost using the format in $this->costFormat or optionally the format given as input
     *
     * @param int $cost Cost
     * @param string $format 'percent', 'msec' or 'usec'
     * @return int Formatted cost
     */
    function formatCost($cost, $format=null) {
        if ($format==null)
            $format = $this->costFormat;

        if ($format == 'percent') {
            $total = $this->getHeader('summary');
            $result = ($total==0) ? 0 : ($cost*100)/$total;
            return number_format($result, 2, '.', '');
        }

        if ($format == 'msec') {
            return round($cost/$this->msecDivisor, 0);
        }

        // Default usec
        return round($cost/$this->usecDivisor, 0);
    }

    private function read($numbers=1) {
        $values = unpack(self::NR_FORMAT.$numbers,fread($this->fp,self::NR_SIZE*$numbers));
        if ($numbers==1)
            return $values[1];
        else
            return array_values($values); // reindex and return
    }

    private function readLine() {
        $result = fgets($this->fp);
        if ($result)
            return trim($result);
        else
            return $result;
    }

    private function seek($offset, $whence=SEEK_SET) {
        return fseek($this->fp, $offset, $whence);
    }

}
