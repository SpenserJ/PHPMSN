<?
# ========================================================================================================
# This class was written by Siebe Tolsma (Copyright 2004, 2005). Based on documentation by ZoRoNaX.
# The class provides a functional backend for creating challenges for use on the MSN network (MSNP11+).
#
# This code is for eductional purposes only. Modification, use and/or publishing this code 
# is entirely on your OWN risk, I can not be held responsible for anything that might result from doing so.
# If you have questions please contact me by posting on the BOT2K3 forum: http://bot2k3.net/forum/
#
# CHANGELOG
# Version 1.02 (4th of March, 2005):
#	Changed: _rawXOr was transformed into _rawBitOp; Can handle multiple raw bitwise operations
#	Changed: _rawBitOp now prefixes the binary strings again (Due to mutli-op handling, like ANDing)
#	Fixed: We now prefix the two pieces of the hash with 0's if necessary before concatting
#	Fixed: No longer use hexdec, but our own _toBase instead (PHP problem with large numbers)
#	Fixed: No longer use PHP's AND function, but our own; _rawBitOp
#
# Version 1.01 (24th of February, 2005):
#	New: Complete rewrite of implementation, including a new class name
#	Changed: Main function (generateCHLHash) now has better parameter support
#	Changed: Settings are now stored in one array; "mxdSettings" rather than tons of variables
#	Changed: Endian Order swapping is now done "auto-magically". !! Doesn't mean you shouldn't test !!
#	Optimized: Class can now handle from/to base convertions with less code handling
#	Optimized: Raw XOr function should be faster due to less prefixing and padding
#	Optimized: Script should work a lot faster now :-)
#
# Version 1.00 (January 18th, 2005):
#	New: Basic CHL implementation code, hurray!
# ========================================================================================================

# For debugging/development purposes:
//error_reporting(E_ALL & E_NOTICE);

# The main class :-)
class Challenge {
	# Define a couple of variables
	var $mxdSettings;
	
	# Implement mother function
	function generateCHLHash($strCHL, $strProdID = "PROD0090YUAUV{2B", $strProdKey = "YMM8C_H7KCQ2S_KL", 
							$intMagicNum = 0x0E79A9C1, $blnSwapEO = true) {
												
		# Push the arguments into the array
		$this->mxdSettings = array("chl" => $strCHL, "prodid" => $strProdID, "prodkey" => $strProdKey, 
									"magicnum" => $intMagicNum, "swapeo" => $blnSwapEO);
		
		# First generate a set of integers from an MD5 hash
		# Then take the strCHL and strProdID and create ints from them too
		# After that create the key (Returned in binary form)
		$intMD5Ints 	= $this->_fetchMD5Ints();
		$intProdIDInts 	= $this->_fetchProdIDInts();
		$strKey 		= $this->_generateKey($intMD5Ints, $intProdIDInts);

		# Finally XOR two parts of a hash with the key and voila!
		$strFinalHash = $this->_xorHash($strKey);
		return $strFinalHash;
	}
	
	################################################################################################################
	################################################################################################################
	
	function _fetchMD5Ints() {
		# Generate hash and split up in chunks of 8 characters
		$strMD5 = md5($this->mxdSettings["chl"] . $this->mxdSettings["prodkey"]);
		$intMD5Ints = explode("\0", chunk_split($strMD5, 8, "\0"));

		# Fetch the integers and AND them
		$str7F = $this->_toBase("7fffffff", 2, 16);
		for($i = 0; $i < 4; $i++) {
			# Do we need to swap to ensure correct endianness?
			if($this->mxdSettings["swapeo"])
				$intMD5Ints[$i] = $this->_swapString($intMD5Ints[$i], 2);

			# First convert to decimal, then AND with 0x7FFFFFFF
			$intMD5Ints[$i] = $this->_toBase($intMD5Ints[$i], 2, 16);
			$intMD5Ints[$i] = $this->_rawBitOp($intMD5Ints[$i], $str7F, 2);
			$intMD5Ints[$i] = $this->_toBase($intMD5Ints[$i], 10, 2);
		}
		
		return $intMD5Ints;
	}
	
	function _fetchProdIDInts() {
		# Create a string from CHL + ProdID, pad to multiple of 8 with 0's
		$strCHLProdID  = $this->mxdSettings["chl"] . $this->mxdSettings["prodid"];
		$strCHLProdID .= str_repeat("0", 8 - (strlen($strCHLProdID) % 8));
		
		# Then split up in parts of 4 and create integers from them :-)
		$intCHLProdIDInts = explode("\0", substr(chunk_split($strCHLProdID, 4, "\0"), 0, -1));
		for($i = 0; $i < count($intCHLProdIDInts); $i++) {
			# Check if we need to swap
			if($this->mxdSettings["swapeo"])
				$intCHLProdIDInts[$i] = $this->_swapString($intCHLProdIDInts[$i], 1);
			
			# Translate to hex and then to decimal
			$intCHLProdIDInts[$i] = $this->_toBase(bin2hex($intCHLProdIDInts[$i]), 10, 16);
		}
		
		return $intCHLProdIDInts;
	}

	################################################################################################################
	################################################################################################################
	
	function _generateKey($intMD5Ints, $intProdIDInts) {
		# Two variables to create our key with
		$key_high = 0; # Represents Hi part of 64Bit number
		$key_low = 0; # Represents Lo part of 64Bit number
		
		# Walk over each 2 elements of the ProdIDInts
		for($i = 0; $i < count($intProdIDInts); $i += 2) {
			# Create a temporary variable
			$key_temp = bcmod(bcadd(bcmul($intProdIDInts[$i], $this->mxdSettings["magicnum"]), $key_high), 0x7FFFFFFF);
			$key_temp = bcmod(bcadd(bcmul($key_temp, $intMD5Ints[0]), $intMD5Ints[1]), 0x7FFFFFFF);

			# Then create the high value of the key
			$key_high = bcmod(bcadd($intProdIDInts[$i+1], $key_temp), 0x7FFFFFFF);
			$key_high = bcmod(bcadd(bcmul($key_high, $intMD5Ints[2]), $intMD5Ints[3]), 0x7FFFFFFF);
			
			# And add the two to the low value of the key
			$key_low = bcadd($key_low, bcadd($key_high, $key_temp));	
		}
		
		# Then add some MD5Ints for the last time and modulo again
		$key_high = bcmod(bcadd($key_high, $intMD5Ints[1]), 0x7FFFFFFF);
		$key_low = bcmod(bcadd($key_low, $intMD5Ints[3]), 0x7FFFFFFF);

		# We should swap .. Or not?
		if($this->mxdSettings["swapeo"]) {
			# Swap the Endian order (This time we can't use our swapString considering they are decimal values)
			$key_high = $this->_swapEO($key_high);
			$key_low = $this->_swapEO($key_low);	
		}

		# Convert to binary and bitshift 32 positions to the left, then add the low int
		$key_high = $this->_toBase($key_high, 2) . str_repeat("0", 32);
		$key_low = $this->_toBase($key_low, 2);
		
		return substr($key_high, 0, strlen($key_high) - strlen($key_low)) . $key_low;
	}
	
	function _xorHash($strKey) {
		# Create two parts of the hash...
		$strMD5 = md5($this->mxdSettings["chl"] . $this->mxdSettings["prodkey"]);
		$intOpChunks = explode("\0", chunk_split($strMD5, 16, "\0"));

		# Then convert the chunks from hex to binary representation
		for($i = 0; $i < 2; $i++)
			$intOpChunks[$i] = $this->_toBase($intOpChunks[$i], 2, 16);

		# Perform a raw XOR on the (binary) numbers
		for($i = 0; $i < 2; $i++) {
			$intOpChunks[$i] = $this->_rawBitOp($intOpChunks[$i], $strKey, 1);
			$intOpChunks[$i] = $this->_toBase($intOpChunks[$i], 16, 2);
			$intOpChunks[$i] = str_repeat("0", 16 - strlen($intOpChunks[$i])) . $intOpChunks[$i];
		}
		
		# Concat and return
		return $intOpChunks[0] . $intOpChunks[1];
	}
	
	################################################################################################################
	################################################################################################################
	
	function _swapString($strToBeSwapped, $intStep) {
		# Quick check for validity
		if($intStep <= 0 or $intStep >= strlen($strToBeSwapped)) 
			return $strToBeSwapped;
		
		# A quick hack with a couple of PHP functions to swap around the string per 2 characters
		$strSwapped = implode("", array_reverse(explode("\0", chunk_split($strToBeSwapped, $intStep, "\0"))));
		return $strSwapped;
	}
	
	function _swapEO($intEndian) {
		# Swap the endian around in 4 simple steps :-)
		$intNewEO = bcmul($intEndian & 0xFF, 0x1000000);
		$intNewEO = bcadd($intNewEO, bcmul($intEndian & 0xFF00, 0x100));
		$intNewEO = bcadd($intNewEO, bcdiv($intEndian & 0xFF0000, 0x100));
		$intNewEO = bcadd($intNewEO, bcdiv($intEndian & 0xFF000000, 0x1000000));
	
		return $intNewEO;
	}
	
	function _rawBitOp($strX, $strY, $intCond) {
		# Get longest string
		$intLenX = strlen($strX);
		$intLenY = strlen($strY);
		$intLongest = ($intLenX > $intLenY ? $intLenX : $intLenY);
		
		# Pad the two strings accordingly
		$strX = str_repeat("0", $intLongest - $intLenX) . $strX;
		$strY = str_repeat("0", $intLongest - $intLenY) . $strY;
		
		# Perform the logic operation
		$strOutput = "";
		for($i = 0; $i < $intLongest; $i++) {
			# Compare to $intCond
			if($strX{$intLongest-1-$i} + $strY{$intLongest-1-$i} == $intCond) {
				$strOutput = "1$strOutput";
			} else { $strOutput = "0$strOutput"; }
		}
		
		# Prefix with remainder of the longest string
		return $strOutput;
	}
	
	function _toBase($intDecimal, $intToBase, $intFromBase = 10) {
		# Takes a intFromBase number and converts to base intToBase
		$strBaseChars = "0123456789abcdef";	
		$strNewBase = "";
		
		# Do we need to conver to decimal first?
		if($intFromBase != 10) 
			$intDecimal = $this->_fromBase($intDecimal, $intFromBase);
		
		# Loop while ...
		while($intDecimal > 0) {
			# Modulo intDecimal by the base, the remainder is the new character
			$strNewBase = $strBaseChars{bcmod($intDecimal, $intToBase)} . $strNewBase;
			$intDecimal = bcdiv($intDecimal, $intToBase);
		}
		
		# Typecast it to a string, to be sure it isnt recognized as an integer (e.g 10101010)
		return $strNewBase;
	}
	
	function _fromBase($strForeign, $intFromBase) {
		# Takes a weird base representation and translates to decimal
		$strBaseChars = "0123456789abcdef";
		$intDecimal = 0;
		
		# Ensure case doesn't matter..
		$strForeign = strtolower($strForeign);
		
		# Walk over each character
		$intForLength = strlen($strForeign) - 1;
		for($i = $intForLength; $i >= 0; $i--) {
			# Add to intDecimal with simple calculation
			$intToPower = bcpow($intFromBase, $intForLength - $i);
			$intDecimal = bcadd($intDecimal, bcmul(strpos($strBaseChars, $strForeign{$i}), $intToPower));
		}

		return $intDecimal;	
	}
}

?>