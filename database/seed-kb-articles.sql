-- Seeds a starter set of Knowledge Base articles (self-help content for common IT issues).
-- Safe to re-run: each INSERT is guarded by a check for an article with the same title.
-- Run against TicketManagement, e.g.:
--   sqlcmd -S "WORKSTATION\JLB282" -E -C -d TicketManagement -i database\seed-kb-articles.sql
USE TicketManagement;
GO

IF NOT EXISTS (SELECT 1 FROM kb_articles WHERE title = N'Printer won''t print: quick fixes before you submit a ticket')
INSERT INTO kb_articles (title, body, category_id, author_id, published)
VALUES (
    N'Printer won''t print: quick fixes before you submit a ticket',
    N'Work through these in order -- most printer problems are solved within the first three.

1. Power-cycle the printer. Turn it off, unplug it for 10 seconds, then plug it back in and let it fully restart before trying to print again.

2. Check the connections. Make sure the USB or network cable is seated, or that the printer still shows connected to Wi-Fi on its display.

3. Clear the print queue. Search Windows for "Print queue," right-click any stuck jobs, and choose Cancel. If the queue is frozen, open Services (services.msc), find Print Spooler, and click Restart.

4. Run the built-in troubleshooter. Go to Settings > Bluetooth & devices > Printers & scanners, select the printer, and run Troubleshoot.

5. Test from another device. If a second computer can print to the same printer, the problem is local to your machine rather than the printer or network.

6. Check for Windows and driver updates. An outdated driver is one of the most common causes of a stuck or failing print job.

If none of these fix it, submit a ticket and include the printer''s name or location and the exact error message you see.',
    1, -- Hardware
    1, -- author_id: Jordan Black (administrator)
    1
);
GO

IF NOT EXISTS (SELECT 1 FROM kb_articles WHERE title = N'Wi-Fi won''t connect')
INSERT INTO kb_articles (title, body, category_id, author_id, published)
VALUES (
    N'Wi-Fi won''t connect',
    N'Work through these in order -- most connection problems are solved in the first two.

1. Confirm Wi-Fi is turned on and Airplane mode is off. These are separate switches; turning off Airplane mode does not always turn Wi-Fi back on.

2. Forget the network and reconnect. Go to Settings > Network & internet > Wi-Fi > Manage known networks, remove the network you''re having trouble with, then reconnect and re-enter the password.

3. Run the network troubleshooter. Settings > Network & Internet > Status > Network troubleshooter will diagnose and often fix the problem automatically.

4. Try the other band. If you see both a 2.4 GHz and a 5 GHz version of the same network name, try connecting to the other one.

5. Test another device. If a phone or another laptop can connect to the same network, the issue is likely specific to your computer, not the network.

6. Update or reinstall the network adapter driver. In Device Manager, find your Wi-Fi adapter under Network adapters, uninstall it, and restart -- Windows will reinstall it automatically.

7. Install pending Windows updates.

A full network reset (Settings > Network & Internet > Advanced network settings > Network reset) removes every network adapter and its settings, so treat it as a last resort before submitting a ticket.',
    3, -- Network
    1, -- author_id: Jordan Black (administrator)
    1
);
GO

IF NOT EXISTS (SELECT 1 FROM kb_articles WHERE title = N'Reset or recover your account password')
INSERT INTO kb_articles (title, body, category_id, author_id, published)
VALUES (
    N'Reset or recover your account password',
    N'If you''re locked out or can''t remember your password, try these steps first.

1. Double-check Caps Lock and your keyboard layout. Many lockouts are simple typing mistakes, not an actual forgotten password.

2. Wait before retrying. Repeated failed attempts can trigger a temporary lockout; wait 15 minutes and try again carefully.

3. Use self-service reset if it''s available to you, rather than waiting on IT. It''s faster and doesn''t require anyone else to verify your identity.

4. If self-service reset isn''t available, submit a ticket from a device where you''re still signed in (or have a coworker submit one on your behalf) so a technician can verify your identity and issue a temporary password.

5. Change the temporary password immediately after signing in -- it''s meant to be used once, not kept.

For your security, technicians can never see your current password and will never ask for it over chat, email, or phone -- only ever set a new one after verifying who you are.',
    4, -- Account Access
    1, -- author_id: Jordan Black (administrator)
    1
);
GO

IF NOT EXISTS (SELECT 1 FROM kb_articles WHERE title = N'Computer running slow? Try this before you submit a ticket')
INSERT INTO kb_articles (title, body, category_id, author_id, published)
VALUES (
    N'Computer running slow? Try this before you submit a ticket',
    N'Slowdowns usually come from one of a handful of causes. Work through these roughly in order.

1. Restart your computer. If you leave it on for days at a time, a restart alone often fixes the slowdown by clearing memory and ending stuck background processes.

2. Close unused programs and browser tabs. Check Task Manager (Ctrl+Shift+Esc) for anything using unusually high CPU or memory.

3. Review your startup programs. In Task Manager''s Startup apps tab, disable anything you don''t need running every time you log in.

4. Free up disk space. Settings > System > Storage can clear temporary files; a drive that''s nearly full slows the whole system down.

5. Run a malware scan with a single antivirus program -- running two at once can itself cause slowdowns.

6. Check for overheating. If the fan runs constantly and the case feels hot, make sure the vents aren''t blocked; overheating causes the CPU to deliberately slow itself down.

7. Install pending Windows updates and restart again.

If performance is still poor after all of the above, submit a ticket -- it may need a hardware diagnostic or a reinstall.',
    2, -- Software
    1, -- author_id: Jordan Black (administrator)
    1
);
GO

SELECT id, title, category_id FROM kb_articles ORDER BY id;
GO
