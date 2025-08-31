import tkinter as tk
from tkinter import messagebox
import socket


def send_udp():
    ssid = ssid_entry.get()
    password = pass_entry.get()
    static_ip = ip_entry.get()
    gateway = gateway_entry.get()
    subnet = subnet_entry.get()

    # بررسی ورودی‌ها
    if not ssid or not password or not static_ip or not gateway or not subnet:
        messagebox.showerror("Error", "لطفاً همه فیلدها را پر کنید!")
        return

    UDP_IP = "192.168.4.1"  # IP ESP8266
    UDP_PORT = 8266

    message = f"{ssid.strip()};{password.strip()};{static_ip.strip()};{gateway.strip()};{subnet.strip()}"

    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.sendto(message.encode(), (UDP_IP, UDP_PORT))
        sock.close()
        messagebox.showinfo("Success", "پیام با موفقیت ارسال شد!")
    except Exception as e:
        messagebox.showerror("Error", f"ارسال پیام موفق نبود:\n{e}")
# ساخت پنجره اصلی
root = tk.Tk()
root.title("ESP8266 UDP Config")

# ایجاد فیلدهای ورودی با مقدار پیش‌فرض
tk.Label(root, text="SSID:").grid(row=0, column=0, padx=5, pady=5)
ssid_entry = tk.Entry(root)
ssid_entry.grid(row=0, column=1, padx=5, pady=5)
ssid_entry.insert(0, "deihim")

tk.Label(root, text="Password:").grid(row=1, column=0, padx=5, pady=5)
pass_entry = tk.Entry(root)
pass_entry.grid(row=1, column=1, padx=5, pady=5)
pass_entry.insert(0, "12345678an")

tk.Label(root, text="Static IP:").grid(row=2, column=0, padx=5, pady=5)
ip_entry = tk.Entry(root)
ip_entry.grid(row=2, column=1, padx=5, pady=5)
ip_entry.insert(0, "192.168.43.")

tk.Label(root, text="Gateway:").grid(row=3, column=0, padx=5, pady=5)
gateway_entry = tk.Entry(root)
gateway_entry.grid(row=3, column=1, padx=5, pady=5)
gateway_entry.insert(0, "192.168.43.1")

tk.Label(root, text="Subnet:").grid(row=4, column=0, padx=5, pady=5)
subnet_entry = tk.Entry(root)
subnet_entry.grid(row=4, column=1, padx=5, pady=5)
subnet_entry.insert(0, "255.255.255.0")

tk.Label(root, text="ESP IP:").grid(row=5, column=0, padx=5, pady=5)
espip_entry = tk.Entry(root)
espip_entry.grid(row=5, column=1, padx=5, pady=5)
espip_entry.insert(0, "192.168.4.1")

# دکمه ارسال
send_button = tk.Button(root, text="Send UDP", command=send_udp)
send_button.grid(row=6, column=0, columnspan=2, pady=10)

root.mainloop()