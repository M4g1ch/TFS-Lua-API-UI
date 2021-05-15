<?php
require_once('Tibia_binary_serializer.class.php');

class Tibia_protocol_API
{
	public $socket;
	public $message;

	// Connect to server and send payload, return response. 
	public function connect($message): string {
		$isUrl = (substr($message, 0,4) == 'http') ? true : false;
		if ($isUrl) {
			$message = file_get_contents($message);
		}
		$this->message = $message;

		$ip = "127.0.0.1";
		$port = "7179";
		$debugging = true;

		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		//AF_UNIX

		// If socket fails to create for some reason
		if (false === $this->socket) {
		    $err = socket_last_error();
		    throw new \RuntimeException("socket_create(AF_UNIX, SOCK_STREAM, SOL_TCP) failed! {$err}: " . socket_strerror($err));
		}

		// ???
		if (!socket_set_block($this->socket)) {
		    $err = socket_last_error($this->socket);
		    throw new \RuntimeException("socket_set_block() failed! {$err}: " . socket_strerror($err));
		}

		// Connect to the server
		if (!socket_connect($this->socket, $ip, $port)) {
		    $err = socket_last_error($this->socket);
		    throw new \RuntimeException("socket_connect() failed! {$err}: " . socket_strerror($err));
		}
		if (!socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1)) {
		    // this actually avoids some bugs, espcially if you try to talk right after login, 
		    // won't work with TCP_NODELAY disabled, but will work with TCP_NODELAY enabled.
		    // (why? not sure.)
		    $err = socket_last_error($this->socket);
		    throw new \RuntimeException("setting TCP_NODELAY failed! {$err}: " . socket_strerror($err));
		} {

			// Send a packet to server
			$packet = new Tibia_binary_serializer();
			$packet->addU8(200);
			$packet->addU16(101);
			$packet->add_string("test");
			$packet->add_string($this->message);
			$this->sendPacket($packet->str(), true);

			return $this->read_next_packet(true, false);
			// Todo: If message exceeds one TCP packet, do a while loop to grab everything
		}
	}

	// Lib func, send TCP packet(s)
	public function sendPacket(string $packet, bool $add_size_header = true): void {
		if ($add_size_header) {
			$len = strlen($packet);
			if ($len > 65535) {
				// note that it's still possible to have several separate packets each individually under 65535 bytes, 
				// concantenated with the Nagle-algorithm but then you have to add the size headers and adler checksums manually, 
				// before calling send()
				throw new OutOfRangeException('Cannot automatically add size header a to a packet above 65535 bytes!');
			}
			$packet = to_little_uint16_t($len) . $packet;
		}
		$this->socket_write_all($this->socket, $packet);
	}
	public static function socket_write_all($socket, string $data): void {
	    if (!($dlen = strlen($data))) {
	        return;
	    }
	    do {
	        assert($dlen > 0);
	        assert(strlen($data) === $dlen);
	        $sent_now = socket_write($socket, $data);
	        if (false === $sent_now) {
	            $err = socket_last_error($socket);
	            throw new \RuntimeException("socket_write() failed! {$err}: " . socket_strerror($err));
	        }
	        if (0 === $sent_now) {
	            // we'll try *1* last time before throwing exception...
	            $sent_now = socket_write($socket, $data);
	            if (false === $sent_now) {
	                $err = socket_last_error($socket);
	                throw new \RuntimeException("socket_write() failed after first returning zero! {$err}: " . socket_strerror($err));
	            }
	            if (0 === $sent_now) {
	                // something is very wrong but it's not registering as an error at the kernel apis...
	                throw new \RuntimeException("socket_write() keeps returning 0 bytes sent while {$dlen} byte(s) to send!");
	            }
	        }
	        $dlen -= $sent_now;
	        $data = substr($data, $sent_now);
	    } while ($dlen > 0);
	    assert($dlen === 0);
	    assert(strlen($data) === 0);
	    // all data sent.
	    return;
	}

	// Lib func, read response packet
	public function read_next_packet(bool $wait_for_packet, bool $remove_size_header = true): ?string {
        $flag = ($wait_for_packet ? MSG_WAITALL : MSG_DONTWAIT);
        $read = '';
        $buf = '';
        // 2 bytes: tibia packet size header, little-endian uint16
        $ret = socket_recv($this->socket, $buf, 2, $flag);
        if ($ret === 0 || ($ret === false && socket_last_error($this->socket) === SOCKET_EWOULDBLOCK)) {
            // no new packet available
            if (!$wait_for_packet) {
                // .. and we're not waiting.
                return null;
            }
            // FIXME socket_recv timed out even with MSG_WAITALL (it's a socksetopt option to change the timeout)
            return null;
        }

        if ($ret === false) {
            // ps: recv error at this stage probably did not corrupt the recv buffer. (unlike in the rest of this function)
            $erri = socket_last_error($this->socket);
            $err = socket_strerror($erri);
            throw new \RuntimeException("socket_recv error {$erri}: {$err}");
        }

        assert(strlen($buf) >= 1);
        $read .= $buf;
        $buf = '';
        if ($ret === 1) {
            // ... we have HALF a size header, wait for the other half regardless of $wait_for_packet (it should come ASAP anyway)
            // (if we don't, then the buffer is in a corrupt state where next read_next_packet will read half a size header!
            //  - another way to handle this would be to use MSG_PEEK but oh well)
            $ret = socket_recv($this->socket, $buf, 1, MSG_WAITALL);
            if ($ret === false) {
                $erri = socket_last_error($this->socket);
                $err = socket_strerror($erri);
                throw new \RuntimeException("socket_recv error {$erri}: {$err} - also: the recv buffer is now in a corrupted state, " .
                    "you should throw away this instance of TibiaClient and re-login (this should never happen btw, you probably have a very unstable connection " .
                    "or a bugged server or something)");
            }
            if ($ret !== 1) {
                throw new \RuntimeException("even with MSG_WAITALL we could only read half a size header! the recv buffer is now in a corrupted state, " .
                    "you should throw away this instance of TibiaClient and re-login (this should never happen btw, you probably have a very unstable connection " .
                    "or a bugged server or something)");
            }
            assert(1 === strlen($buf));
            $read .= $buf;
            $buf = '';
        }

        assert(2 === strlen($read));
        assert(0 === strlen($buf));

        $size = from_little_uint16_t($read);
        while (0 < ($remaining = (($size + 2) - strlen($read)))) {
            $buf = '';
            $ret = socket_recv($this->socket, $buf, $remaining, MSG_WAITALL);
            if ($ret === false) {
                $erri = socket_last_error($this->socket);
                $err = socket_strerror($erri);
                throw new \RuntimeException("socket_recv error {$erri}: {$err} - also: the recv buffer is now in a corrupted state, " .
                    "you should throw away this instance of TibiaClient and re-login (this should never happen btw, you probably have a very unstable connection " .
                    "or a bugged server or something)");
            }
            if (0 === $ret) {
                throw new \RuntimeException("even with MSG_WAITALL and trying to read {$remaining} bytes, socket_recv return 0! something is very wrong. " .
                    "also the recv buffer is now in a corrupted state, you should throw away this instance of TibiaClient and re-login. " .
                    "(this should never happen btw, you probably have a very unstable connection " .
                    "or a bugged server or something)");
            }
            $read .= $buf;
        }
        if ($remaining !== 0) {
            throw new \LogicException("...wtf, after the read loop, remaining was: " . hhb_return_var_dump($remaining) . " - should never happen, probably a code bug.");
        }
        if (strlen($read) !== ($size + 2)) {
            throw new \LogicException('...wtf, `strlen($read) === ($size + 2)` sanity check failed, should never happen, probably a code bug.');
        }
        assert(strlen($read) >= 2);
        if ($remove_size_header) {
            $read = substr($read, 2);
        }
        return $read;
    }
}


$protocol_API = new Tibia_protocol_API();

$message = (isset($_GET['message'])) ? $_GET['message'] : "print('Message not configured.')";
$response = $protocol_API->connect($message);
?>
<p>Send to server:</p>
<form action="" method="get">
	<textarea name="message"><?php echo $message; ?></textarea><br>
	<input type="submit" value="Send message to TFS server">
</form>
<p>Response from server:</p>
<textarea><?php echo $response; ?></textarea>
<style type="text/css">
	textarea {
		width: 500px;
		height: 300px;
		clear: both;
	}
	p {
		margin-bottom: 0;
		font-weight: bold;
	}
</style>