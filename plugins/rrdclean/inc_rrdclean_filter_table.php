	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="form_rrdclean" method="get" action="rrdcleaner.php">
		<td>
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr>
					<td width="55">
						&nbsp;Records:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyFilterChange(document.form_rrdclean)">
						<?php
						if (sizeof($item_rows) > 0) {
						foreach ($item_rows as $key => $value) {
							print '<option value="' . $key . '"'; if ($_REQUEST["rows"] == $key) { print " selected"; } print ">" . $value . "</option>\n";
						}
						}
						?>
						</select>
					</td>
					<td width="60">
						&nbsp;Search:&nbsp;
					</td>
					<td width="1">
						<input type="text" name="filter" size="40" value="<?php print $_REQUEST["filter"];?>">
					</td>
					<td>
						&nbsp;<input type="submit" value="Go" alt="Go">
						<input type="submit" value="Clear" name="clear_x" alt="Clear">
						<input type="submit" value="Rescan" name="rescan" alt="Refresh">
					</td>
				</tr>
			</table>
		</td>
		<input type='hidden' name='page' value='1'>
		</form>
	</tr>