// מעטפת דקה סביב Tango (ya-webadb, MIT) — מה שדף ההקמה של מגדלור צריך, ולא יותר.
import { Adb, AdbDaemonTransport } from "@yume-chan/adb";
import { AdbDaemonWebUsbDeviceManager, AdbDefaultInterfaceFilter } from "@yume-chan/adb-daemon-webusb";
import AdbWebCredentialStore from "@yume-chan/adb-credential-web";

export const supported = () => !!AdbDaemonWebUsbDeviceManager.BROWSER;

/** בוחרים טלפון (חלון הבחירה של הדפדפן), מתחברים ומאמתים — הטלפון מציג "לאפשר ניפוי באגים?". */
export async function connect() {
  const manager = AdbDaemonWebUsbDeviceManager.BROWSER;
  if (!manager) throw Object.assign(new Error("no-webusb"), { code: "no-webusb" });
  const device = await manager.requestDevice({ filters: [AdbDefaultInterfaceFilter] });
  if (!device) throw Object.assign(new Error("no-device"), { code: "no-device" });
  let connection;
  try { connection = await device.connect(); }
  catch (e) { throw Object.assign(new Error(String(e && e.message || e)), { code: e && e.name === "DeviceBusyError" ? "busy" : "usb", cause: e }); }
  const transport = await AdbDaemonTransport.authenticate({
    serial: device.serial, connection, credentialStore: new AdbWebCredentialStore("מגדלור"),
  });
  const adb = new Adb(transport);
  return {
    serial: device.serial,
    /** פקודת shell אחת — הפלט כטקסט (stdout ו-stderr יחד). */
    run: (cmd) => adb.subprocess.noneProtocol.spawnWaitText(cmd),
    close: () => adb.close().catch(() => {}),
  };
}
